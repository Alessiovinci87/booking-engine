<?php

use App\Domain\Payments\Enums\PaymentStatus;
use App\Domain\Reservations\Enums\ReservationStatus;

/* AC3 con Stripe in modalità test: autorizzazione reale, approvazione, capture reale. */

beforeEach(fn () => $this->account = liveStripe());

it('richiesta pre-autorizzata, approvata e incassata', function () {
    $fx = liveTenant($this->account, 'REQUEST');
    $reservation = startCheckout($fx);
    $authorized = livePay($fx, $reservation);

    expect(reloadReservation($fx, $reservation->id)->status)->toBe(ReservationStatus::AWAITING_APPROVAL)
        ->and($authorized->status)->toBe(PaymentStatus::AUTHORIZED)
        ->and($authorized->capture_before)->not->toBeNull();

    inTenant($fx['tenant'], fn () => machine()->approve(reloadReservation($fx, $reservation->id)));

    $intent = liveStripeClient()->paymentIntents->retrieve((string) $authorized->payment_intent_id, [], ['stripe_account' => $fx['account']]);
    expect(reloadReservation($fx, $reservation->id)->status)->toBe(ReservationStatus::CONFIRMED)
        ->and(latestPayment($fx, $reservation->id)->captured_cents)->toBe($authorized->amount_cents)
        ->and($intent->status)->toBe('succeeded');
});
