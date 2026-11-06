<?php

namespace App\Domain\Payments\Jobs;

use App\Domain\Payments\Enums\PaymentStatus;
use App\Domain\Payments\Gateway\Exceptions\PaymentDeclined;
use App\Domain\Payments\Gateway\Exceptions\PaymentProviderUnavailable;
use App\Domain\Payments\Gateway\PaymentGateway;
use App\Domain\Payments\Models\Payment;
use App\Domain\Reservations\Enums\ReservationStatus;
use App\Domain\Reservations\Models\Reservation;
use App\Domain\Reservations\StateMachines\ReservationStateMachine;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Capture dopo l'approvazione (05 flusso B, passo 4) con idempotency key `capture:{payment_id}`.
 * La chiamata al fornitore è fuori dalle transazioni: lettura, chiamata, registrazione dell'esito.
 * Riuscita → T12; rifiuto definitivo → T13; esito sconosciuto → T14 (decide la riconciliazione, M4).
 */
final class CapturePaymentJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(public readonly string $tenantId, public readonly string $paymentId) {}

    public function handle(TenantContext $tenants, PaymentGateway $gateway, ReservationStateMachine $reservations): void
    {
        $payment = $tenants->run($this->tenantId, fn (): ?Payment => Payment::query()
            ->whereKey($this->paymentId)->where('status', PaymentStatus::CAPTURE_PENDING->value)->first());

        if ($payment === null || $payment->payment_intent_id === null || $payment->stripe_account_id === null) {
            return;
        }

        try {
            $result = $gateway->capture($payment->stripe_account_id, $payment->payment_intent_id, $payment->amount_cents, 'capture:'.$payment->id);
        } catch (PaymentDeclined $e) {
            $tenants->run($this->tenantId, fn () => $this->whenCapturing($payment, fn (Reservation $r) => $reservations->captureFailed($r, $payment, $e->declineCode)));

            return;
        } catch (PaymentProviderUnavailable) {
            $tenants->run($this->tenantId, fn () => $this->whenCapturing($payment, fn (Reservation $r) => $reservations->captureUnknown($r)));

            return;
        }

        $tenants->run($this->tenantId, fn () => $this->whenCapturing($payment, fn (Reservation $r) => $reservations->captureSucceeded($r, $payment, $result->capturedCents)));
    }

    /** @param  callable(Reservation): mixed  $apply */
    private function whenCapturing(Payment $payment, callable $apply): void
    {
        $payment->refresh();
        $reservation = Reservation::query()->findOrFail($payment->reservation_id);

        if ($reservation->status === ReservationStatus::CAPTURING && $payment->status === PaymentStatus::CAPTURE_PENDING) {
            $apply($reservation);
        }
    }
}
