<?php

namespace App\Domain\Payments;

use App\Domain\Payments\Enums\PaymentStatus;
use App\Domain\Payments\Gateway\Exceptions\PaymentProviderUnavailable;
use App\Domain\Payments\Gateway\PaymentGateway;
use App\Domain\Payments\Gateway\PaymentIntentStatus;
use App\Domain\Payments\Jobs\CapturePaymentJob;
use App\Domain\Payments\Models\Payment;
use App\Domain\Payments\StateMachines\PaymentStateMachine;
use App\Domain\Reservations\Enums\ReservationStatus;
use App\Domain\Reservations\Models\Reservation;
use App\Domain\Reservations\StateMachines\ReservationStateMachine;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Support\Facades\Log;

/**
 * Allineamento di un pagamento con ciò che si legge da Stripe (05 "Webhook" passo 6 e
 * "Riconciliazione"). Usato dagli eventi payment_intent.* e dai giri di riconciliazione:
 *
 * - PENDING con sessione → esito della sessione (CheckoutOutcomes: T3, T4, T8); EXPIRED o FAILED con
 *   sessione poi risultata pagata → T7 (rimborso o annullo, I4);
 * - AUTHORIZED → se l'autorizzazione risulta annullata o scaduta lato Stripe, AUTHORIZATION_CANCELLED;
 * - CAPTURE_PENDING → PaymentIntent incassato: T12; annullato: T13; ancora da incassare: nuova
 *   capture con la stessa idempotency key (I9).
 *
 * Le letture a Stripe sono fuori dalle transazioni; le transizioni si applicano solo se lo stato
 * locale è ancora quello letto all'inizio (I3).
 */
final class PaymentReconciler
{
    public function __construct(
        private readonly TenantContext $tenants,
        private readonly PaymentGateway $gateway,
        private readonly CheckoutOutcomes $outcomes,
        private readonly PaymentStateMachine $payments,
        private readonly ReservationStateMachine $reservations,
    ) {}

    /** @return 'applied'|'unchanged'|'retry' */
    public function payment(string $tenantId, string $paymentId): string
    {
        $payment = $this->tenants->run($tenantId, fn (): ?Payment => Payment::query()->find($paymentId));

        if ($payment === null || $payment->stripe_account_id === null) {
            return 'unchanged';
        }

        // PENDING: esito della sessione; EXPIRED e FAILED: un pagamento arrivato dopo il rilascio (T7).
        if (in_array($payment->status, [PaymentStatus::PENDING, PaymentStatus::EXPIRED, PaymentStatus::FAILED], true)) {
            if ($payment->checkout_session_id === null) {
                return 'unchanged';
            }

            return $this->outcomes->process($tenantId, $payment->id) ? 'applied' : 'retry';
        }

        if (! in_array($payment->status, [PaymentStatus::AUTHORIZED, PaymentStatus::CAPTURE_PENDING], true) || $payment->payment_intent_id === null) {
            return 'unchanged';
        }

        try {
            $intent = $this->gateway->retrievePaymentIntent($payment->stripe_account_id, $payment->payment_intent_id);
        } catch (PaymentProviderUnavailable) {
            return 'retry';
        }

        if ($payment->status === PaymentStatus::AUTHORIZED) {
            if ($intent->status !== PaymentIntentStatus::CANCELED) {
                return 'unchanged';
            }

            return $this->tenants->run($tenantId, function () use ($payment): string {
                $locked = Payment::query()->lockForUpdate()->findOrFail($payment->id);

                if ($locked->status !== PaymentStatus::AUTHORIZED) {
                    return 'unchanged';
                }

                $this->payments->markAuthorizationCancelled($locked);
                Log::warning('payments.authorization_cancelled_by_provider', ['payment_id' => $locked->id, 'reservation_id' => $locked->reservation_id]);

                return 'applied';
            });
        }

        if ($intent->status === PaymentIntentStatus::REQUIRES_CAPTURE) {
            CapturePaymentJob::dispatch($tenantId, $payment->id);

            return 'unchanged';
        }

        if (! in_array($intent->status, [PaymentIntentStatus::SUCCEEDED, PaymentIntentStatus::CANCELED], true)) {
            return 'unchanged';
        }

        return $this->tenants->run($tenantId, function () use ($payment, $intent): string {
            $locked = Payment::query()->lockForUpdate()->findOrFail($payment->id);
            $reservation = Reservation::query()->findOrFail($locked->reservation_id);

            if ($locked->status !== PaymentStatus::CAPTURE_PENDING || $reservation->status !== ReservationStatus::CAPTURING) {
                return 'unchanged';
            }

            $intent->status === PaymentIntentStatus::SUCCEEDED
                ? $this->reservations->captureSucceeded($reservation, $locked, $intent->amountReceivedCents)
                : $this->reservations->captureFailed($reservation, $locked, $intent->failureCode ?? 'authorization_canceled');

            return 'applied';
        });
    }
}
