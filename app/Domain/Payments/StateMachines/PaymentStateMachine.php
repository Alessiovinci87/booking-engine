<?php

namespace App\Domain\Payments\StateMachines;

use App\Domain\Payments\Enums\CaptureMethod;
use App\Domain\Payments\Enums\OfflineMethod;
use App\Domain\Payments\Enums\PaymentPurpose;
use App\Domain\Payments\Enums\PaymentStatus as S;
use App\Domain\Payments\Models\Payment;
use App\Domain\Payments\Models\PaymentEvent;
use App\Domain\Reservations\Exceptions\IllegalTransition;
use App\Domain\Reservations\Models\Reservation;
use App\Domain\Tenancy\Audit\AuditLogger;
use Carbon\CarbonImmutable;

/**
 * Macchina a stati del pagamento (03, sezione 2). Ogni cambio di stato scrive un PaymentEvent
 * (append-only, I7) e l'audit. Chi chiama è dentro una transazione con contesto tenant.
 */
final class PaymentStateMachine
{
    /**
     * Stato di arrivo → stati di partenza ammessi. Da EXPIRED o FAILED si arriva ad AUTHORIZED o
     * SUCCEEDED solo con un esito tardivo (T7, ADR-0011): i soldi sono arrivati davvero e vanno
     * restituiti, quindi il pagamento deve rifletterlo.
     *
     * @var array<string, list<S>>
     */
    private const TRANSITIONS = [
        'AUTHORIZED' => [S::PENDING, S::EXPIRED, S::FAILED],
        'SUCCEEDED' => [S::PENDING, S::CAPTURE_PENDING, S::EXPIRED, S::FAILED],
        'EXPIRED' => [S::PENDING],
        'FAILED' => [S::PENDING],
        'CAPTURE_PENDING' => [S::AUTHORIZED],
        'AUTHORIZATION_CANCELLED' => [S::AUTHORIZED],
        'CAPTURE_FAILED' => [S::CAPTURE_PENDING],
        'PARTIALLY_REFUNDED' => [S::SUCCEEDED, S::PARTIALLY_REFUNDED],
        'REFUNDED' => [S::SUCCEEDED, S::PARTIALLY_REFUNDED],
    ];

    public function __construct(private readonly AuditLogger $audit) {}

    /** Nuovo tentativo online in attesa della sessione (05, passo 1). */
    public function createPending(Reservation $reservation, PaymentPurpose $purpose, int $amountCents, CaptureMethod $captureMethod, string $stripeAccountId): Payment
    {
        $payment = new Payment;
        $payment->tenant_id = $reservation->tenant_id;
        $payment->reservation_id = $reservation->id;
        $payment->purpose = $purpose;
        $payment->amount_cents = $amountCents;
        $payment->capture_method = $captureMethod;
        $payment->status = S::PENDING;
        $payment->stripe_account_id = $stripeAccountId;
        $payment->save();

        $this->event($payment, 'created', $amountCents);

        return $payment;
    }

    /** Pagamento registrato fuori piattaforma: nasce SUCCEEDED (03, 05 flusso D). */
    public function createOffline(Reservation $reservation, int $amountCents, OfflineMethod $method): Payment
    {
        $payment = new Payment;
        $payment->tenant_id = $reservation->tenant_id;
        $payment->reservation_id = $reservation->id;
        $payment->purpose = PaymentPurpose::OFFLINE;
        $payment->amount_cents = $amountCents;
        $payment->capture_method = CaptureMethod::AUTOMATIC;
        $payment->status = S::SUCCEEDED;
        $payment->method_offline = $method;
        $payment->captured_cents = $amountCents;
        $payment->save();

        $this->event($payment, 'offline_recorded', $amountCents);

        return $payment;
    }

    /** Sessione creata dal fornitore (05, passo 3): non è un cambio di stato. */
    public function attachSession(Payment $payment, string $sessionId, CarbonImmutable $expiresAt): void
    {
        $payment->checkout_session_id = $sessionId;
        $payment->checkout_expires_at = $expiresAt;
        $payment->save();

        $this->event($payment, 'checkout_session_created', null, $sessionId);
    }

    public function markAuthorized(Payment $payment, string $paymentIntentId, ?CarbonImmutable $captureBefore): void
    {
        $payment->payment_intent_id = $paymentIntentId;
        $payment->capture_before = $captureBefore;
        $this->move($payment, S::AUTHORIZED, $paymentIntentId);
    }

    public function markSucceeded(Payment $payment, ?string $paymentIntentId, int $capturedCents): void
    {
        $payment->payment_intent_id ??= $paymentIntentId;
        $payment->captured_cents = $capturedCents;
        $this->move($payment, S::SUCCEEDED, $payment->payment_intent_id, $capturedCents);
    }

    public function markExpired(Payment $payment): void
    {
        $this->move($payment, S::EXPIRED, $payment->checkout_session_id);
    }

    public function markFailed(Payment $payment, ?string $failureCode): void
    {
        $payment->failure_code = $failureCode;
        $this->move($payment, S::FAILED, $payment->payment_intent_id);
    }

    public function requestCapture(Payment $payment): void
    {
        $this->move($payment, S::CAPTURE_PENDING, $payment->payment_intent_id);
    }

    public function markCaptureFailed(Payment $payment, string $failureCode): void
    {
        $payment->failure_code = $failureCode;
        $this->move($payment, S::CAPTURE_FAILED, $payment->payment_intent_id);
    }

    public function markAuthorizationCancelled(Payment $payment): void
    {
        $this->move($payment, S::AUTHORIZATION_CANCELLED, $payment->payment_intent_id);
    }

    /** Dopo un rimborso riuscito: parziale o a saldo zero (03, sezione 2). */
    public function applyRefund(Payment $payment, int $refundedCents): void
    {
        $this->move($payment, $refundedCents >= $payment->captured_cents ? S::REFUNDED : S::PARTIALLY_REFUNDED, null, $refundedCents);
    }

    private function move(Payment $payment, S $to, ?string $objectId, ?int $amount = null): void
    {
        $from = $payment->status;

        if (! in_array($from, self::TRANSITIONS[$to->value], true)) {
            throw IllegalTransition::of('payment', $from->value, $to->value);
        }

        $payment->status = $to;
        $payment->save();

        $this->event($payment, strtolower($to->value), $amount, $objectId);
        $this->audit->record('payment.'.strtolower($to->value), 'payment', $payment->id, [
            'status' => ['from' => $from->value, 'to' => $to->value], 'reservation_id' => $payment->reservation_id,
        ]);
    }

    /**
     * Contestazione aperta o chiusa (05, 03): nessun cambio di stato, solo PaymentEvent e audit. Un
     * evento per contestazione e tipo (I3).
     *
     * @return bool true se l'evento è nuovo
     */
    public function recordDispute(Payment $payment, string $type, int $amountCents, string $disputeId): bool
    {
        if (PaymentEvent::query()->where('payment_id', $payment->id)->where('type', $type)->where('stripe_object_id', $disputeId)->exists()) {
            return false;
        }

        $this->event($payment, $type, $amountCents, $disputeId);
        $this->audit->record('payment.'.$type, 'payment', $payment->id, [
            'reservation_id' => $payment->reservation_id, 'amount_cents' => $amountCents, 'dispute_id' => $disputeId,
        ]);

        return true;
    }

    private function event(Payment $payment, string $type, ?int $amount, ?string $objectId = null): void
    {
        $event = new PaymentEvent;
        $event->payment_id = $payment->id;
        $event->type = $type;
        $event->amount_cents = $amount;
        $event->stripe_object_id = $objectId;
        $event->occurred_at = CarbonImmutable::now();
        $event->save();
    }
}
