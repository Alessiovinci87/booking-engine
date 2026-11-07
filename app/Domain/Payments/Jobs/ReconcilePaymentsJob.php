<?php

namespace App\Domain\Payments\Jobs;

use App\Domain\Payments\CheckoutService;
use App\Domain\Payments\Enums\PaymentPurpose;
use App\Domain\Payments\Enums\PaymentStatus;
use App\Domain\Payments\Enums\RefundStatus;
use App\Domain\Payments\Models\Payment;
use App\Domain\Payments\Models\Refund;
use App\Domain\Payments\PaymentReconciler;
use App\Domain\Payments\RefundService;
use App\Domain\Reservations\Enums\ReservationStatus;
use App\Domain\Reservations\Models\Reservation;
use App\Domain\Tenancy\Exceptions\BusinessRuleViolation;
use App\Domain\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Riconciliazione dei pagamenti di un tenant (05 "Riconciliazione", 4.8):
 *
 * - Payment PENDING senza sessione da oltre 5 minuti → recupero con la stessa idempotency key;
 * - Payment AUTHORIZED → PaymentIntent ancora `requires_capture`; capture_before vicina → avviso;
 *   autorizzazione di una prenotazione chiusa non annullata dopo 24 h → allerta e nuovo annullo;
 * - Payment CAPTURE_PENDING → chiude l'esito (T12/T13 o nuova capture con la stessa chiave); oltre
 *   1 ora in CAPTURING → allerta (T14);
 * - Refund PENDING da oltre 1 ora → rilettura; Refund FAILED con tentativo scaduto → nuovo tentativo.
 *
 * Le chiamate a Stripe sono fuori dalle transazioni (niente InteractsWithTenant). Un errore su un
 * pagamento non ferma gli altri; nei log solo ID.
 */
final class ReconcilePaymentsJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public const FULL = 'full';

    public const CAPTURING = 'capturing';

    public const PENDING_WITHOUT_SESSION_MINUTES = 5;

    public const PENDING_REFUND_MINUTES = 60;

    private const CAPTURING_ALERT_MINUTES = 60;

    private const UNCANCELLED_ALERT_HOURS = 24;

    private const CAPTURE_BEFORE_WARNING_HOURS = 6;

    public int $tries = 1;

    public int $uniqueFor = 600;

    public function __construct(public readonly string $tenantId, public readonly string $scope = self::FULL) {}

    public function uniqueId(): string
    {
        return $this->tenantId.':'.$this->scope;
    }

    public function handle(TenantContext $tenants, PaymentReconciler $reconciler, CheckoutService $checkout, RefundService $refunds): void
    {
        $now = CarbonImmutable::now();

        $payments = $tenants->run($this->tenantId, function () use ($now): array {
            $query = Payment::query()->where('status', PaymentStatus::CAPTURE_PENDING->value);

            if ($this->scope === self::FULL) {
                $query->orWhere('status', PaymentStatus::AUTHORIZED->value)
                    ->orWhere(fn ($q) => $q->where('status', PaymentStatus::PENDING->value)
                        ->where('purpose', '<>', PaymentPurpose::OFFLINE->value)
                        ->whereNull('checkout_session_id')
                        ->where('created_at', '<', $now->subMinutes(self::PENDING_WITHOUT_SESSION_MINUTES)));
            }

            return $query->limit(200)->get()->all();
        });

        foreach ($payments as $payment) {
            try {
                $this->payment($tenants, $reconciler, $checkout, $payment, $now);
            } catch (Throwable $e) {
                Log::warning('payments.reconcile_failed', ['tenant_id' => $this->tenantId, 'payment_id' => $payment->id, 'exception' => $e::class]);
            }
        }

        if ($this->scope !== self::FULL) {
            return;
        }

        $pendingRefunds = $tenants->run($this->tenantId, fn (): array => Refund::query()
            ->where('status', RefundStatus::PENDING->value)
            ->where('updated_at', '<', $now->subMinutes(self::PENDING_REFUND_MINUTES))
            ->limit(200)->get()->all());

        foreach ($pendingRefunds as $refund) {
            try {
                $refund->stripe_refund_id === null
                    ? ProcessRefundJob::dispatch($this->tenantId, $refund->id)
                    : $refunds->reconcile($this->tenantId, $refund->id);
            } catch (Throwable $e) {
                Log::warning('payments.reconcile_failed', ['tenant_id' => $this->tenantId, 'refund_id' => $refund->id, 'exception' => $e::class]);
            }
        }

        $refunds->retryDue($this->tenantId);
    }

    private function payment(TenantContext $tenants, PaymentReconciler $reconciler, CheckoutService $checkout, Payment $payment, CarbonImmutable $now): void
    {
        $reservation = $tenants->run($this->tenantId, fn (): Reservation => Reservation::query()->findOrFail($payment->reservation_id));

        if ($payment->status === PaymentStatus::PENDING) {
            if ($reservation->status === ReservationStatus::AWAITING_PAYMENT) {
                try {
                    $checkout->openSession($this->tenantId, $payment->id);
                } catch (BusinessRuleViolation) {
                    // Stripe non risponde o rifiuta: ci pensa il job di rilascio alla scadenza provvisoria.
                }
            }

            return;
        }

        $reconciler->payment($this->tenantId, $payment->id);

        if ($payment->status === PaymentStatus::CAPTURE_PENDING && $payment->updated_at !== null
            && $payment->updated_at->lessThan($now->subMinutes(self::CAPTURING_ALERT_MINUTES))) {
            $current = $tenants->run($this->tenantId, fn (): ?PaymentStatus => Payment::query()->find($payment->id)?->status);

            if ($current === PaymentStatus::CAPTURE_PENDING) {
                Log::error('payments.capturing_overdue', ['tenant_id' => $this->tenantId, 'payment_id' => $payment->id, 'reservation_id' => $payment->reservation_id]);
            }
        }

        if ($payment->status !== PaymentStatus::AUTHORIZED) {
            return;
        }

        if ($reservation->status === ReservationStatus::AWAITING_APPROVAL && $payment->capture_before !== null
            && $payment->capture_before->lessThan($now->addHours(self::CAPTURE_BEFORE_WARNING_HOURS))) {
            Log::warning('payments.capture_before_near', ['tenant_id' => $this->tenantId, 'payment_id' => $payment->id]);
        }

        // Autorizzazione di una prenotazione chiusa (rifiutata, scaduta, pagamento tardivo) ancora da annullare.
        if ($reservation->status->isTerminal() && $payment->updated_at !== null) {
            CancelAuthorizationJob::dispatch($this->tenantId, $payment->id);

            if ($payment->updated_at->lessThan($now->subHours(self::UNCANCELLED_ALERT_HOURS))) {
                Log::error('payments.authorization_not_cancelled', ['tenant_id' => $this->tenantId, 'payment_id' => $payment->id]);
            }
        }
    }
}
