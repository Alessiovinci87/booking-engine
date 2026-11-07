<?php

use App\Domain\Payments\Enums\PaymentStatus;
use App\Domain\Reservations\Enums\ReservationStatus;

/*
 * AC2 (senza feed) con Stripe in modalità test: sessione reale sull'account connesso, pagamento con
 * pm_card_visa, conferma solo dalla rilettura via API.
 */

beforeEach(fn () => $this->account = liveStripe());

it('prenotazione immediata pagata e confermata', function () {
    $fx = liveTenant($this->account, 'INSTANT');
    $reservation = startCheckout($fx);
    $payment = latestPayment($fx, $reservation->id);

    expect($payment->checkout_session_id)->toStartWith('cs_test_')
        ->and($reservation->status)->toBe(ReservationStatus::AWAITING_PAYMENT);

    $paid = livePay($fx, $reservation);

    expect(reloadReservation($fx, $reservation->id)->status)->toBe(ReservationStatus::CONFIRMED)
        ->and($paid->status)->toBe(PaymentStatus::SUCCEEDED)
        ->and($paid->captured_cents)->toBe($paid->amount_cents)
        ->and(occupancyViolations($fx['tenant']))->toBe([]);
});
