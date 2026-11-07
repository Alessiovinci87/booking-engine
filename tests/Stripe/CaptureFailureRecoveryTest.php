<?php

use App\Domain\Payments\CheckoutService;
use App\Domain\Reservations\Enums\ReservationStatus;

/*
 * AC14 con Stripe in modalità test: autorizzazione annullata su Stripe prima dell'approvazione → la
 * capture fallisce, date ancora occupate (CAPTURE_FAILED); il link di recupero crea una nuova sessione
 * reale e il pagamento conferma.
 */

beforeEach(fn () => $this->account = liveStripe());

it('capture fallita, link di recupero pagato → CONFIRMED', function () {
    $fx = liveTenant($this->account, 'REQUEST');
    $reservation = startCheckout($fx);
    $authorized = livePay($fx, $reservation);
    liveStripeClient()->paymentIntents->cancel((string) $authorized->payment_intent_id, [], ['stripe_account' => $fx['account']]);

    inTenant($fx['tenant'], fn () => machine()->approve(reloadReservation($fx, $reservation->id)));

    expect(reloadReservation($fx, $reservation->id)->status)->toBe(ReservationStatus::CAPTURE_FAILED)
        ->and(occupancyViolations($fx['tenant']))->toBe([]);

    $session = app(CheckoutService::class)->sendRecoveryLink($fx['tenant'], $reservation->id, 24);
    expect($session->id)->toStartWith('cs_test_')
        ->and(reloadReservation($fx, $reservation->id)->status)->toBe(ReservationStatus::AWAITING_PAYMENT);

    livePay($fx, reloadReservation($fx, $reservation->id));

    expect(reloadReservation($fx, $reservation->id)->status)->toBe(ReservationStatus::CONFIRMED);
});
