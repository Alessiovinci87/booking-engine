<?php

use App\Domain\Payments\CheckoutService;
use App\Domain\Payments\Models\Refund;
use App\Domain\Reservations\Enums\ReservationStatus;
use Illuminate\Support\Carbon;

/*
 * I4 con Stripe in modalità test: la sessione viene chiusa su Stripe alla scadenza (expire reale) e le
 * date liberate; un pagamento registrato dopo non conferma: la prenotazione resta EXPIRED e parte il
 * rimborso reale (T7).
 */

beforeEach(fn () => $this->account = liveStripe());
afterEach(fn () => Carbon::setTestNow());

it('pagamento tardivo: resta EXPIRED e viene rimborsato', function () {
    $fx = liveTenant($this->account, 'INSTANT');
    $reservation = startCheckout($fx);
    $session = (string) latestPayment($fx, $reservation->id)->checkout_session_id;

    Carbon::setTestNow(now()->addMinutes(40));
    expect(app(CheckoutService::class)->releaseExpired($fx['tenant'], $reservation->id))->toBe('released')
        ->and(liveStripeClient()->checkout->sessions->retrieve($session, [], ['stripe_account' => $fx['account']])->status)->toBe('expired');

    livePay($fx, reloadReservation($fx, $reservation->id));

    $refund = inTenant($fx['tenant'], fn () => Refund::query()->sole());
    expect(reloadReservation($fx, $reservation->id)->status)->toBe(ReservationStatus::EXPIRED)
        ->and($refund->stripe_refund_id)->toStartWith('re_')
        ->and(occupancyViolations($fx['tenant']))->toBe([]);
});
