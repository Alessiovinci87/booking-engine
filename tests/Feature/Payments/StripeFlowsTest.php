<?php

use App\Domain\Payments\Enums\PaymentStatus;
use App\Domain\Payments\PaymentReconciler;
use App\Domain\Reservations\Enums\ReservationStatus;
use Illuminate\Support\Carbon;

/*
 * 4.4 — Flusso A (immediata) e Flusso B (pre-autorizzazione, approvazione, rifiuto, capture) con
 * Stripe simulato (05). Conferme solo dal webhook con rilettura via API; capture e annullo con le
 * idempotency key capture:{payment_id} e cancel:{payment_id} sull'account connesso.
 */

beforeEach(function () {
    Carbon::setTestNow('2027-06-01 10:00:00');
    $this->sim = stripeSimulator();
});
afterEach(function () {
    resetStripeHttp();
    Carbon::setTestNow();
});

it('Flusso A: pagamento con capture automatica → CONFIRMED con l\'importo incassato', function () {
    $fx = bookableTenant('INSTANT');
    $reservation = stripeGuestPays($this, $this->sim, $fx, startCheckout($fx));
    $payment = latestPayment($fx, $reservation->id);

    expect($reservation->status)->toBe(ReservationStatus::CONFIRMED)
        ->and($reservation->confirmation_kind?->value)->toBe('ONLINE_PAYMENT')
        ->and($payment->status)->toBe(PaymentStatus::SUCCEEDED)
        ->and($payment->captured_cents)->toBe($payment->amount_cents)
        ->and($payment->payment_intent_id)->toStartWith('pi_test_')
        ->and(occupancyViolations($fx['tenant']))->toBe([]);
});

it('Flusso A: carta rifiutata dentro Checkout → la prenotazione resta in attesa e si può ripagare (ADR-0012)', function () {
    $fx = bookableTenant('INSTANT');
    $reservation = startCheckout($fx);
    $sessionId = (string) latestPayment($fx, $reservation->id)->checkout_session_id;

    $intent = $this->sim->decline($sessionId);
    postStripeWebhook($this, $this->sim->event('payment_intent.payment_failed', $intent, $fx['account']))->assertOk();
    expect(reloadReservation($fx, $reservation->id)->status)->toBe(ReservationStatus::AWAITING_PAYMENT)
        ->and($this->sim->sessions[$sessionId]['status'])->toBe('open');

    expect(stripeGuestPays($this, $this->sim, $fx, $reservation)->status)->toBe(ReservationStatus::CONFIRMED);
});

it('Flusso B: autorizzazione → AWAITING_APPROVAL; approvazione → capture della caparra → CONFIRMED', function () {
    $fx = bookableTenant('REQUEST');
    $reservation = stripeGuestPays($this, $this->sim, $fx, startCheckout($fx));
    $payment = latestPayment($fx, $reservation->id);

    expect($reservation->status)->toBe(ReservationStatus::AWAITING_APPROVAL)
        ->and($payment->status)->toBe(PaymentStatus::AUTHORIZED)
        ->and($payment->capture_before)->not->toBeNull()
        ->and($reservation->approval_deadline->equalTo(now()->addHours(12)))->toBeTrue();

    inTenant($fx['tenant'], fn () => machine()->approve($reservation));

    $capture = $this->sim->http->requestsTo('post', '/v1/payment_intents/.+/capture')[0];
    expect($capture['idempotency_key'])->toBe('capture:'.$payment->id)
        ->and($capture['account'])->toBe($fx['account'])
        ->and($capture['params']['amount_to_capture'])->toBe($payment->amount_cents)
        ->and(reloadReservation($fx, $reservation->id)->status)->toBe(ReservationStatus::CONFIRMED)
        ->and(latestPayment($fx, $reservation->id)->status)->toBe(PaymentStatus::SUCCEEDED)
        ->and(occupancyViolations($fx['tenant']))->toBe([]);
});

it('Flusso B: rifiuto → REJECTED, date libere, autorizzazione annullata con cancel:{payment_id}', function () {
    $fx = bookableTenant('REQUEST');
    $reservation = stripeGuestPays($this, $this->sim, $fx, startCheckout($fx));
    $payment = latestPayment($fx, $reservation->id);

    inTenant($fx['tenant'], fn () => machine()->reject($reservation, 'Date non più disponibili'));

    $cancel = $this->sim->http->requestsTo('post', '/v1/payment_intents/.+/cancel')[0];
    expect($cancel['idempotency_key'])->toBe('cancel:'.$payment->id)
        ->and($this->sim->intents[$payment->payment_intent_id]['status'])->toBe('canceled')
        ->and(reloadReservation($fx, $reservation->id)->status)->toBe(ReservationStatus::REJECTED)
        ->and(latestPayment($fx, $reservation->id)->status)->toBe(PaymentStatus::AUTHORIZATION_CANCELLED)
        ->and(occupancyViolations($fx['tenant']))->toBe([]);
});

it('Flusso B: capture rifiutata dalla banca → CAPTURE_FAILED con le date ancora occupate', function () {
    $fx = bookableTenant('REQUEST');
    $reservation = stripeGuestPays($this, $this->sim, $fx, startCheckout($fx));
    $this->sim->declineNextCapture = 'insufficient_funds';

    inTenant($fx['tenant'], fn () => machine()->approve($reservation));

    $payment = latestPayment($fx, $reservation->id);
    expect(reloadReservation($fx, $reservation->id)->status)->toBe(ReservationStatus::CAPTURE_FAILED)
        ->and($payment->status)->toBe(PaymentStatus::CAPTURE_FAILED)
        ->and($payment->failure_code)->toBe('insufficient_funds')
        ->and(occupancyViolations($fx['tenant']))->toBe([]);
});

it('Flusso B: esito della capture sconosciuto → CAPTURING, poi la riconciliazione chiude con la stessa chiave', function () {
    $fx = bookableTenant('REQUEST');
    $reservation = stripeGuestPays($this, $this->sim, $fx, startCheckout($fx));
    $this->sim->http->fail('post', '/v1/payment_intents/.+/capture', 500, 'api_error', times: 1);

    inTenant($fx['tenant'], fn () => machine()->approve($reservation));
    expect(reloadReservation($fx, $reservation->id)->status)->toBe(ReservationStatus::CAPTURING);

    $payment = latestPayment($fx, $reservation->id);
    app(PaymentReconciler::class)->payment($fx['tenant'], $payment->id);

    $captures = $this->sim->http->requestsTo('post', '/v1/payment_intents/.+/capture');
    expect($captures)->toHaveCount(2)
        ->and(array_unique(array_column($captures, 'idempotency_key')))->toBe(['capture:'.$payment->id])
        ->and(reloadReservation($fx, $reservation->id)->status)->toBe(ReservationStatus::CONFIRMED);
});

it('payment_intent.canceled su un\'autorizzazione in attesa la segna annullata', function () {
    $fx = bookableTenant('REQUEST');
    $reservation = stripeGuestPays($this, $this->sim, $fx, startCheckout($fx));
    $payment = latestPayment($fx, $reservation->id);
    $this->sim->intents[$payment->payment_intent_id]['status'] = 'canceled';

    postStripeWebhook($this, $this->sim->event('payment_intent.canceled', $this->sim->intents[$payment->payment_intent_id], $fx['account']))->assertOk();

    expect(latestPayment($fx, $reservation->id)->status)->toBe(PaymentStatus::AUTHORIZATION_CANCELLED)
        ->and(reloadReservation($fx, $reservation->id)->status)->toBe(ReservationStatus::AWAITING_APPROVAL);
});
