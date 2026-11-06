<?php

namespace App\Domain\Reservations\Jobs;

use App\Domain\Inventory\Enums\OccupancyTempReason;
use App\Domain\Inventory\InventoryService;
use App\Domain\Payments\CheckoutService;
use App\Domain\Reservations\Enums\ReservationStatus;
use App\Domain\Reservations\Exceptions\IllegalTransition;
use App\Domain\Reservations\Models\Reservation;
use App\Domain\Reservations\StateMachines\ReservationStateMachine;
use App\Domain\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Rilascio delle occupazioni scadute di un tenant (05, 03):
 *
 * - CHECKOUT e PAYMENT_RECOVERY: CheckoutService::releaseExpired chiude la sessione sul fornitore
 *   fuori transazione e poi applica T5, oppure T3/T4 se la sessione risulta completata (T6); se il
 *   fornitore non risponde non rilascia e riprova al giro successivo, con un avviso dopo 30 minuti;
 * - AWAITING_APPROVAL a approval_deadline: T11 (annullo dell'autorizzazione dopo il commit);
 * - CAPTURE_FAILED a decision_deadline: T16.
 *
 * Non usa InteractsWithTenant: quella avvolgerebbe tutto il job in una transazione, mentre le chiamate
 * al fornitore devono stare fuori. Ogni passo apre il proprio TenantContext::run(). Un errore su una
 * prenotazione non ferma le altre; nei log solo ID (09).
 */
final class OccupancyReleaseJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    /** @var list<OccupancyTempReason> */
    public const REASONS = [
        OccupancyTempReason::CHECKOUT,
        OccupancyTempReason::PAYMENT_RECOVERY,
        OccupancyTempReason::AWAITING_APPROVAL,
        OccupancyTempReason::CAPTURE_FAILED,
    ];

    private const OVERDUE_ALERT_MINUTES = 30;

    public int $tries = 1;

    public int $uniqueFor = 300;

    public function __construct(public readonly string $tenantId) {}

    public function uniqueId(): string
    {
        return $this->tenantId;
    }

    public function handle(TenantContext $tenants, InventoryService $inventory, CheckoutService $checkout, ReservationStateMachine $machine): void
    {
        $now = CarbonImmutable::now();
        $batch = (int) config('payments.release_batch_size', 100);

        $payments = $tenants->run($this->tenantId, fn (): array => $inventory->expiredReservations($now, [OccupancyTempReason::CHECKOUT, OccupancyTempReason::PAYMENT_RECOVERY], $batch));

        foreach ($payments as $item) {
            try {
                $outcome = $checkout->releaseExpired($this->tenantId, $item['reservation_id']);
            } catch (Throwable $e) {
                $outcome = 'retry';
                Log::warning('occupancy_release.failed', ['tenant_id' => $this->tenantId, 'reservation_id' => $item['reservation_id'], 'exception' => $e::class]);
            }

            if ($outcome === 'retry' && $item['expires_at']->lessThan($now->subMinutes(self::OVERDUE_ALERT_MINUTES))) {
                Log::error('occupancy_release.overdue', ['tenant_id' => $this->tenantId, 'reservation_id' => $item['reservation_id'], 'expired_at' => $item['expires_at']->toIso8601ZuluString()]);
            }
        }

        $timeouts = [
            [OccupancyTempReason::AWAITING_APPROVAL, ReservationStatus::AWAITING_APPROVAL, fn (Reservation $r): Reservation => $machine->approvalExpired($r)],
            [OccupancyTempReason::CAPTURE_FAILED, ReservationStatus::CAPTURE_FAILED, fn (Reservation $r): Reservation => $machine->releaseDates($r)],
        ];

        foreach ($timeouts as [$reason, $status, $apply]) {
            $due = $tenants->run($this->tenantId, fn (): array => $inventory->expiredReservations($now, [$reason], $batch));

            foreach ($due as $item) {
                try {
                    $tenants->run($this->tenantId, function () use ($item, $status, $apply): void {
                        $reservation = Reservation::query()->find($item['reservation_id']);

                        if ($reservation !== null && $reservation->status === $status) {
                            $apply($reservation);
                        }
                    });
                } catch (IllegalTransition) {
                    // Cambiata nel frattempo (approvata, recuperata, cancellata): niente da fare.
                } catch (Throwable $e) {
                    Log::warning('occupancy_release.failed', ['tenant_id' => $this->tenantId, 'reservation_id' => $item['reservation_id'], 'exception' => $e::class]);
                }
            }
        }
    }
}
