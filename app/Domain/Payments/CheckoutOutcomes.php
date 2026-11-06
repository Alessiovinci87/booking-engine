<?php

namespace App\Domain\Payments;

use App\Domain\Payments\Enums\PaymentStatus;
use App\Domain\Payments\Gateway\CheckoutSessionState;
use App\Domain\Payments\Gateway\CheckoutSessionStatus;
use App\Domain\Payments\Gateway\Exceptions\PaymentProviderUnavailable;
use App\Domain\Payments\Gateway\Exceptions\SessionAlreadyCompleted;
use App\Domain\Payments\Gateway\PaymentGateway;
use App\Domain\Payments\Models\Payment;
use App\Domain\Reservations\Enums\ReservationStatus;
use App\Domain\Reservations\Models\Reservation;
use App\Domain\Reservations\StateMachines\ReservationStateMachine;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Support\Facades\Log;

/**
 * Esito di una sessione di checkout, sempre riletto dal fornitore (05 "Webhook" passo 6, I2): lo usano
 * il webhook (M4), il checkout simulato e il job di rilascio (T6). Applica solo transizioni ammesse e
 * ignora ciò che è già stato applicato (I3):
 *
 * - pagato o autorizzato con prenotazione AWAITING_PAYMENT → T3 o T4;
 * - pagato o autorizzato con prenotazione già rilasciata → T7 (rimborso o annullo, I4);
 * - pagamento rifiutato → chiusura della sessione, poi T8;
 * - sessione scaduta → nulla: il rilascio è del job (I16).
 */
final class CheckoutOutcomes
{
    public function __construct(
        private readonly TenantContext $tenants,
        private readonly PaymentGateway $gateway,
        private readonly ReservationStateMachine $reservations,
    ) {}

    public function process(string $tenantId, string $paymentId): void
    {
        $payment = $this->tenants->run($tenantId, fn (): ?Payment => Payment::query()->find($paymentId));

        if ($payment === null || $payment->checkout_session_id === null || $payment->stripe_account_id === null) {
            return;
        }

        try {
            $state = $this->gateway->retrieveSession($payment->stripe_account_id, $payment->checkout_session_id);

            if ($state->status === CheckoutSessionStatus::OPEN && $state->paymentFailed) {
                // T8: prima si chiude la sessione, poi si rilascia (03). Se nel frattempo risulta pagata,
                // si prosegue con il pagamento.
                try {
                    $state = $this->gateway->expireSession($payment->stripe_account_id, $payment->checkout_session_id, 'expire:'.$payment->id);
                    $state = new CheckoutSessionState($state->id, $state->status, $state->expiresAt, $state->amountCents, $state->metadata,
                        $state->paymentIntentId, $state->paymentIntentStatus, true, $state->failureCode ?? 'payment_failed', $state->captureBefore);
                } catch (SessionAlreadyCompleted) {
                    $state = $this->gateway->retrieveSession($payment->stripe_account_id, $payment->checkout_session_id);
                }
            }
        } catch (PaymentProviderUnavailable) {
            Log::warning('payments.outcome_retry', ['payment_id' => $paymentId]);

            return;
        }

        $this->tenants->run($tenantId, fn () => $this->apply($paymentId, $state));
    }

    private function apply(string $paymentId, CheckoutSessionState $state): void
    {
        $payment = Payment::query()->lockForUpdate()->findOrFail($paymentId);
        $reservation = Reservation::query()->findOrFail($payment->reservation_id);
        $paidOrAuthorized = $state->status === CheckoutSessionStatus::COMPLETE && ($state->isPaid() || $state->isAuthorized());

        if ($paidOrAuthorized && $state->paymentIntentId !== null) {
            $alreadyApplied = in_array($payment->status, [PaymentStatus::SUCCEEDED, PaymentStatus::AUTHORIZED, PaymentStatus::CAPTURE_PENDING,
                PaymentStatus::PARTIALLY_REFUNDED, PaymentStatus::REFUNDED, PaymentStatus::AUTHORIZATION_CANCELLED], true);

            if ($alreadyApplied) {
                return;
            }

            if ($reservation->status === ReservationStatus::AWAITING_PAYMENT && $payment->status === PaymentStatus::PENDING) {
                $state->isPaid()
                    ? $this->reservations->paymentSucceeded($reservation, $payment, $state->paymentIntentId, $state->amountCents)
                    : $this->reservations->authorizationSucceeded($reservation, $payment, $state->paymentIntentId, $state->captureBefore);

                return;
            }

            if (in_array($reservation->status, [ReservationStatus::EXPIRED, ReservationStatus::PAYMENT_FAILED], true)) {
                $this->reservations->latePayment($reservation, $payment, $state->paymentIntentId, $state->isAuthorized(),
                    $state->isPaid() ? $state->amountCents : 0, $state->captureBefore);
            }

            return;
        }

        if ($state->paymentFailed && $state->status !== CheckoutSessionStatus::OPEN
            && $reservation->status === ReservationStatus::AWAITING_PAYMENT && $payment->status === PaymentStatus::PENDING) {
            $this->reservations->paymentFailed($reservation, $payment, $state->failureCode);
        }
    }
}
