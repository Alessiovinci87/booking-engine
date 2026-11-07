<?php

use App\Domain\Payments\Enums\PaymentStatus;
use App\Domain\Reservations\Enums\ReservationStatus;

/* AC4 con Stripe in modalità test: richiesta rifiutata, autorizzazione annullata su Stripe. */

beforeEach(fn () => $this->account = liveStripe());

it('richiesta rifiutata: autorizzazione annullata, nessun incasso', function () {
    $fx = liveTenant($this->account, 'REQUEST');
    $reservation = startCheckout($fx);
    $authorized = livePay($fx, $reservation);

    inTenant($fx['tenant'], fn () => machine()->reject(reloadReservation($fx, $reservation->id)));

    $intent = liveStripeClient()->paymentIntents->retrieve((string) $authorized->payment_intent_id, [], ['stripe_account' => $fx['account']]);
    expect(reloadReservation($fx, $reservation->id)->status)->toBe(ReservationStatus::REJECTED)
        ->and(latestPayment($fx, $reservation->id)->status)->toBe(PaymentStatus::AUTHORIZATION_CANCELLED)
        ->and($intent->status)->toBe('canceled')
        ->and(occupancyViolations($fx['tenant']))->toBe([]);
});
