<?php

use App\Domain\Payments\Enums\PaymentStatus;
use App\Domain\Reservations\Enums\ReservationStatus;
use App\Domain\Reservations\Exceptions\IllegalTransition;
use App\Domain\Reservations\Jobs\OccupancyReleaseJob;
use Illuminate\Support\Carbon;

/*
 * AC4 (01, 03 "Timeout", 05 flusso B): la finestra di approvazione è min(12 h, capture_before − 6 h);
 * alla scadenza il job applica T11 (date libere) e annulla l'autorizzazione con cancel:{payment_id};
 * dopo la scadenza il gestore non può più approvare. Stripe simulato.
 */

beforeEach(function () {
    Carbon::setTestNow('2027-06-01 10:00:00');
    $this->sim = stripeSimulator();
    $this->fx = bookableTenant('REQUEST');
});
afterEach(function () {
    resetStripeHttp();
    Carbon::setTestNow();
});

it('la finestra è di 12 ore se l\'autorizzazione dura abbastanza', function () {
    $reservation = stripeGuestPays($this, $this->sim, $this->fx, startCheckout($this->fx));

    expect($reservation->approval_deadline->toIso8601String())->toBe('2027-06-01T22:00:00+00:00');
});

it('la finestra si accorcia a capture_before − 6 h', function () {
    $reservation = startCheckout($this->fx);
    $sessionId = (string) latestPayment($this->fx, $reservation->id)->checkout_session_id;
    $intent = $this->sim->pay($sessionId);
    $this->sim->intents[$intent['id']]['latest_charge']['payment_method_details']['card']['capture_before'] = now()->addHours(10)->getTimestamp();
    postStripeWebhook($this, $this->sim->event('checkout.session.completed', $this->sim->lastSessionView($sessionId), $this->fx['account']))->assertOk();

    expect(reloadReservation($this->fx, $reservation->id)->approval_deadline->toIso8601String())->toBe('2027-06-01T14:00:00+00:00');
});

it('alla scadenza il job rilascia le date (T11) e annulla l\'autorizzazione', function () {
    $reservation = stripeGuestPays($this, $this->sim, $this->fx, startCheckout($this->fx));
    $payment = latestPayment($this->fx, $reservation->id);

    Carbon::setTestNow('2027-06-01 21:59:00');
    OccupancyReleaseJob::dispatchSync($this->fx['tenant']);
    expect(reloadReservation($this->fx, $reservation->id)->status)->toBe(ReservationStatus::AWAITING_APPROVAL);

    Carbon::setTestNow('2027-06-01 22:00:01');
    OccupancyReleaseJob::dispatchSync($this->fx['tenant']);

    expect(reloadReservation($this->fx, $reservation->id)->status)->toBe(ReservationStatus::EXPIRED)
        ->and(latestPayment($this->fx, $reservation->id)->status)->toBe(PaymentStatus::AUTHORIZATION_CANCELLED)
        ->and($this->sim->http->requestsTo('post', '/v1/payment_intents/.+/cancel')[0]['idempotency_key'])->toBe('cancel:'.$payment->id)
        ->and(occupancyViolations($this->fx['tenant']))->toBe([]);
});

it('dopo la scadenza l\'approvazione è rifiutata e non parte nessuna capture', function () {
    $reservation = stripeGuestPays($this, $this->sim, $this->fx, startCheckout($this->fx));
    Carbon::setTestNow('2027-06-01 22:00:01');

    expect(fn () => inTenant($this->fx['tenant'], fn () => machine()->approve($reservation)))->toThrow(IllegalTransition::class)
        ->and($this->sim->http->requestsTo('post', '/v1/payment_intents/.+/capture'))->toBe([]);
});
