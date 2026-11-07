<?php

use App\Domain\Inventory\Models\InventoryOccupancy;
use App\Domain\Payments\CheckoutService;
use App\Domain\Payments\Enums\PaymentStatus;
use App\Domain\Reservations\Enums\ReservationStatus;
use App\Domain\Reservations\Models\Reservation;
use App\Domain\Tenancy\Exceptions\BusinessRuleViolation;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;

/*
 * 4.2 — StripePaymentGateway e CheckoutService con Stripe simulato (05 "Creazione del checkout"):
 * sequenza in tre passi, header Stripe-Account, idempotency key checkout:{payment_id}, parametri
 * della sessione derivati dalla prenotazione, allineamento delle scadenze, recupero con la stessa
 * chiave dopo un crash tra i passi 2 e 3 e rilascio se Stripe non ha mai creato la sessione.
 */

beforeEach(function () {
    Carbon::setTestNow('2027-06-01 10:00:00');
    $this->sim = stripeSimulator();
});
afterEach(function () {
    resetStripeHttp();
    Carbon::setTestNow();
});

function occupancyExpiry(array $fx, string $reservationId): ?CarbonImmutable
{
    return inTenant($fx['tenant'], fn () => InventoryOccupancy::query()->where('reservation_id', $reservationId)->where('status', 'ACTIVE')->first()?->expires_at);
}

it('crea la sessione sull\'account connesso con i parametri e la chiave di 05', function () {
    $fx = bookableTenant('INSTANT');
    $reservation = startCheckout($fx);
    $payment = latestPayment($fx, $reservation->id);
    $request = $this->sim->http->requestsTo('post', '/v1/checkout/sessions')[0];
    $params = $request['params'];

    expect($request['account'])->toBe($fx['account'])
        ->and($request['idempotency_key'])->toBe('checkout:'.$payment->id)
        ->and($params['mode'])->toBe('payment')
        ->and($params['payment_method_types'])->toBe(['card'])
        ->and($params['payment_intent_data']['capture_method'])->toBe('automatic')
        ->and($params['line_items'][0]['price_data']['currency'])->toBe('eur')
        ->and($params['line_items'][0]['price_data']['unit_amount'])->toBe($payment->amount_cents)
        ->and($params['metadata'])->toBe(['reservation_id' => $reservation->id, 'payment_id' => $payment->id, 'tenant_id' => $fx['tenant']])
        ->and($params['payment_intent_data']['metadata'])->toBe($params['metadata'])
        ->and($params['success_url'])->toContain('session={CHECKOUT_SESSION_ID}')
        ->and($params['expires_at'])->toBe(CarbonImmutable::parse($payment->created_at)->addMinutes(30)->addSeconds(120)->getTimestamp())
        ->and(json_encode($params['metadata']))->not->toContain('@');

    $session = $this->sim->lastSession();
    expect($payment->checkout_session_id)->toBe($session['id'])
        ->and($payment->checkout_expires_at->getTimestamp())->toBe($session['expires_at'])
        ->and(occupancyExpiry($fx, $reservation->id)->getTimestamp())->toBe($session['expires_at'] + 5 * 60);
});

it('una richiesta usa la capture manuale e la sola caparra', function () {
    $fx = bookableTenant('REQUEST');
    $reservation = startCheckout($fx);
    $payment = latestPayment($fx, $reservation->id);
    $params = $this->sim->http->requestsTo('post', '/v1/checkout/sessions')[0]['params'];

    expect($params['payment_intent_data']['capture_method'])->toBe('manual')
        ->and($payment->amount_cents)->toBe($reservation->deposit_due_cents)->toBeLessThan($reservation->contract_total_cents)
        ->and($params['line_items'][0]['price_data']['unit_amount'])->toBe($payment->amount_cents);
});

it('crash tra passo 2 e 3: il job ritrova la sessione con la stessa chiave e la chiude', function () {
    $fx = bookableTenant('INSTANT');
    $this->sim->loseNextCreateResponse = true;

    $created = null;

    try {
        app(CheckoutService::class)->start($fx['tenant'], inTenant($fx['tenant'], fn () => newReservationData($fx)));
    } catch (BusinessRuleViolation $e) {
        $created = $e;
    }

    expect($created?->errorCode)->toBe('PAYMENT_PROVIDER_UNAVAILABLE');
    $reservation = inTenant($fx['tenant'], fn () => Reservation::query()->firstOrFail());
    $payment = latestPayment($fx, $reservation->id);
    expect($payment->checkout_session_id)->toBeNull()
        ->and(occupancyExpiry($fx, $reservation->id)->equalTo(CarbonImmutable::now()->addMinutes(45)))->toBeTrue();

    Carbon::setTestNow(now()->addMinutes(46));
    expect(app(CheckoutService::class)->releaseExpired($fx['tenant'], $reservation->id))->toBe('released');

    $creates = $this->sim->http->requestsTo('post', '/v1/checkout/sessions');
    expect($creates)->toHaveCount(2)
        ->and($creates[1]['idempotency_key'])->toBe($creates[0]['idempotency_key'])
        ->and($this->sim->sessions)->toHaveCount(1)
        ->and(array_values($this->sim->sessions)[0]['status'])->toBe('expired')
        ->and($this->sim->http->requestsTo('post', '/v1/checkout/sessions/.+/expire')[0]['idempotency_key'])->toBe('expire:'.$payment->id)
        ->and(reloadReservation($fx, $reservation->id)->status)->toBe(ReservationStatus::EXPIRED)
        ->and(latestPayment($fx, $reservation->id)->status)->toBe(PaymentStatus::EXPIRED)
        ->and(occupancyViolations($fx['tenant']))->toBe([]);
});

it('Stripe non ha mai creato la sessione: alla scadenza provvisoria si rilascia', function () {
    $fx = bookableTenant('INSTANT');
    $this->sim->http->fail('post', '/v1/checkout/sessions', 503, 'api_error', times: 1);

    expect(fn () => app(CheckoutService::class)->start($fx['tenant'], inTenant($fx['tenant'], fn () => newReservationData($fx))))
        ->toThrow(BusinessRuleViolation::class);
    $reservation = inTenant($fx['tenant'], fn () => Reservation::query()->firstOrFail());

    Carbon::setTestNow(now()->addMinutes(46));
    expect(app(CheckoutService::class)->releaseExpired($fx['tenant'], $reservation->id))->toBe('released');

    // Il secondo tentativo con la stessa chiave viene rifiutato (expires_at ormai passato): nessuna sessione.
    expect($this->sim->sessions)->toBe([])
        ->and(reloadReservation($fx, $reservation->id)->status)->toBe(ReservationStatus::EXPIRED)
        ->and(occupancyViolations($fx['tenant']))->toBe([]);
});

it('Stripe irraggiungibile durante il rilascio: nessun rilascio, nuovo tentativo al giro dopo', function () {
    $fx = bookableTenant('INSTANT');
    $reservation = startCheckout($fx);
    Carbon::setTestNow(now()->addMinutes(40));
    $this->sim->http->fail('post', '/v1/checkout/sessions/.+/expire', 500, 'api_error', times: 1);

    expect(app(CheckoutService::class)->releaseExpired($fx['tenant'], $reservation->id))->toBe('retry')
        ->and(reloadReservation($fx, $reservation->id)->status)->toBe(ReservationStatus::AWAITING_PAYMENT);

    expect(app(CheckoutService::class)->releaseExpired($fx['tenant'], $reservation->id))->toBe('released')
        ->and(reloadReservation($fx, $reservation->id)->status)->toBe(ReservationStatus::EXPIRED);
});
