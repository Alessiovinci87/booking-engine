<?php

use App\Domain\Payments\CheckoutService;
use App\Domain\Reservations\Enums\ReservationStatus;
use Illuminate\Support\Carbon;

/*
 * AC15, I16 con Stripe in modalità test: la sessione risulta pagata prima del webhook; alla scadenza
 * provvisoria il job non rilascia ma rilegge la sessione e conferma (T6).
 */

beforeEach(fn () => $this->account = liveStripe());
afterEach(fn () => Carbon::setTestNow());

it('sessione completata scoperta dal job: nessun rilascio, CONFIRMED', function () {
    $fx = liveTenant($this->account, 'INSTANT');
    $reservation = startCheckout($fx);
    livePay($fx, $reservation, deliver: false);

    Carbon::setTestNow(now()->addMinutes(40));

    expect(app(CheckoutService::class)->releaseExpired($fx['tenant'], $reservation->id))->toBe('completed')
        ->and(reloadReservation($fx, $reservation->id)->status)->toBe(ReservationStatus::CONFIRMED)
        ->and(occupancyViolations($fx['tenant']))->toBe([]);
});
