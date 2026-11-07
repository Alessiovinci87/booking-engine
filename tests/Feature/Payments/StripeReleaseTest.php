<?php

use App\Domain\Payments\CheckoutService;
use App\Domain\Payments\Enums\PaymentStatus;
use App\Domain\Payments\Enums\RefundStatus;
use App\Domain\Payments\Models\Refund;
use App\Domain\Reservations\Enums\ReservationStatus;
use App\Domain\Reservations\Jobs\OccupancyReleaseJob;
use Illuminate\Support\Carbon;

/*
 * 4.5 — Rilascio delle occupazioni con sessione (05, I16) con Stripe simulato: expire con
 * expire:{payment_id}; sessione già completata scoperta dal job → nessun rilascio, T3/T4 (T6, AC15);
 * pagamento arrivato dopo il rilascio → rimborso o annullo automatici (T7, I4).
 */

beforeEach(function () {
    Carbon::setTestNow('2027-06-01 10:00:00');
    $this->sim = stripeSimulator();
});
afterEach(function () {
    resetStripeHttp();
    Carbon::setTestNow();
});

it('sessione abbandonata: il job la chiude su Stripe e libera le date (T5)', function () {
    $fx = bookableTenant('INSTANT');
    $reservation = startCheckout($fx);
    $payment = latestPayment($fx, $reservation->id);

    Carbon::setTestNow(now()->addMinutes(36));
    OccupancyReleaseJob::dispatchSync($fx['tenant']);
    expect(reloadReservation($fx, $reservation->id)->status)->toBe(ReservationStatus::AWAITING_PAYMENT);

    Carbon::setTestNow(now()->addMinutes(2));
    OccupancyReleaseJob::dispatchSync($fx['tenant']);

    expect(reloadReservation($fx, $reservation->id)->status)->toBe(ReservationStatus::EXPIRED)
        ->and(latestPayment($fx, $reservation->id)->status)->toBe(PaymentStatus::EXPIRED)
        ->and($this->sim->sessions[$payment->checkout_session_id]['status'])->toBe('expired')
        ->and($this->sim->http->requestsTo('post', '/v1/checkout/sessions/.+/expire')[0]['idempotency_key'])->toBe('expire:'.$payment->id)
        ->and(occupancyViolations($fx['tenant']))->toBe([]);
});

it('AC15/T6: la sessione risulta pagata prima del webhook → nessun rilascio, CONFIRMED', function () {
    $fx = bookableTenant('INSTANT');
    $reservation = startCheckout($fx);
    $payment = latestPayment($fx, $reservation->id);
    $this->sim->pay((string) $payment->checkout_session_id);

    Carbon::setTestNow(now()->addMinutes(40));
    OccupancyReleaseJob::dispatchSync($fx['tenant']);

    expect(reloadReservation($fx, $reservation->id)->status)->toBe(ReservationStatus::CONFIRMED)
        ->and(latestPayment($fx, $reservation->id)->status)->toBe(PaymentStatus::SUCCEEDED);

    // Il webhook arriva dopo: nessun effetto doppio.
    postStripeWebhook($this, $this->sim->event('checkout.session.completed', $this->sim->lastSessionView((string) $payment->checkout_session_id), $fx['account']))->assertOk();
    expect(reloadReservation($fx, $reservation->id)->status)->toBe(ReservationStatus::CONFIRMED)
        ->and(occupancyViolations($fx['tenant']))->toBe([]);
});

it('T6 su una richiesta: sessione autorizzata scoperta dal job → AWAITING_APPROVAL', function () {
    $fx = bookableTenant('REQUEST');
    $reservation = startCheckout($fx);
    $this->sim->pay((string) latestPayment($fx, $reservation->id)->checkout_session_id);

    Carbon::setTestNow(now()->addMinutes(40));
    expect(app(CheckoutService::class)->releaseExpired($fx['tenant'], $reservation->id))->toBe('completed')
        ->and(reloadReservation($fx, $reservation->id)->status)->toBe(ReservationStatus::AWAITING_APPROVAL);
});

it('I4/T7: pagamento registrato dopo il rilascio → resta EXPIRED e parte il rimborso con refund:{id}', function () {
    $fx = bookableTenant('INSTANT');
    $reservation = startCheckout($fx);
    $sessionId = (string) latestPayment($fx, $reservation->id)->checkout_session_id;
    Carbon::setTestNow(now()->addMinutes(40));
    app(CheckoutService::class)->releaseExpired($fx['tenant'], $reservation->id);
    expect(reloadReservation($fx, $reservation->id)->status)->toBe(ReservationStatus::EXPIRED);

    // Corsa lato Stripe: il pagamento risulta comunque completato.
    $this->sim->sessions[$sessionId]['status'] = 'open';
    $this->sim->pay($sessionId);
    postStripeWebhook($this, $this->sim->event('checkout.session.completed', $this->sim->lastSessionView($sessionId), $fx['account']))->assertOk();

    $refund = inTenant($fx['tenant'], fn () => Refund::query()->sole());
    $create = $this->sim->http->requestsTo('post', '/v1/refunds')[0];
    expect(reloadReservation($fx, $reservation->id)->status)->toBe(ReservationStatus::EXPIRED)
        ->and($create['idempotency_key'])->toBe('refund:'.$refund->id)
        ->and($create['params']['metadata'])->toMatchArray(['refund_id' => $refund->id, 'tenant_id' => $fx['tenant']])
        ->and($refund->status)->toBe(RefundStatus::SUCCEEDED)
        ->and(occupancyViolations($fx['tenant']))->toBe([]);
});

it('T7 su una richiesta: autorizzazione arrivata dopo il rilascio → annullata, nessun incasso', function () {
    $fx = bookableTenant('REQUEST');
    $reservation = startCheckout($fx);
    $sessionId = (string) latestPayment($fx, $reservation->id)->checkout_session_id;
    Carbon::setTestNow(now()->addMinutes(40));
    app(CheckoutService::class)->releaseExpired($fx['tenant'], $reservation->id);

    $this->sim->sessions[$sessionId]['status'] = 'open';
    $intent = $this->sim->pay($sessionId);
    postStripeWebhook($this, $this->sim->event('checkout.session.completed', $this->sim->lastSessionView($sessionId), $fx['account']))->assertOk();

    expect(reloadReservation($fx, $reservation->id)->status)->toBe(ReservationStatus::EXPIRED)
        ->and($this->sim->intents[$intent['id']]['status'])->toBe('canceled')
        ->and($this->sim->http->requestsTo('post', '/v1/payment_intents/.+/capture'))->toBe([]);
});
