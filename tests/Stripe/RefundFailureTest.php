<?php

use App\Domain\Payments\Enums\RefundStatus;
use App\Domain\Payments\Gateway\RefundResultStatus;
use App\Domain\Payments\Models\Refund;
use App\Domain\Payments\RefundService;
use App\Domain\Reservations\CancellationRefund;

/*
 * AC19 con Stripe in modalità test: pm_card_refundFail (carta 4000 0000 0000 5126) rende il rimborso
 * fallito in modo asincrono. La rilettura via API porta il rimborso a FAILED con un nuovo tentativo
 * programmato.
 *
 * Da verificare al primo giro: Stripe documenta che con questa carta il rimborso può risultare prima
 * "succeeded" e poi "failed"; se il RefundService lo ha già chiuso come SUCCEEDED il test lo segnala.
 */

beforeEach(fn () => $this->account = liveStripe());

it('rimborso fallito su Stripe → FAILED con nuovo tentativo', function () {
    $fx = liveTenant($this->account, 'INSTANT');
    $reservation = startCheckout($fx);
    livePay($fx, $reservation, 'pm_card_refundFail');
    $confirmed = reloadReservation($fx, $reservation->id);

    inTenant($fx['tenant'], fn () => machine()->cancel($confirmed, CancellationRefund::for($confirmed, null), null, null));
    $refund = inTenant($fx['tenant'], fn () => Refund::query()->sole());

    $deadline = time() + 60;
    do {
        $state = liveGateway()->retrieveRefund($fx['account'], (string) $refund->stripe_refund_id);
        if ($state->status === RefundResultStatus::FAILED) {
            break;
        }
        usleep(2_000_000);
    } while (time() < $deadline);

    expect($state->status)->toBe(RefundResultStatus::FAILED);

    app(RefundService::class)->reconcile($fx['tenant'], $refund->id, $refund->stripe_refund_id);

    $current = inTenant($fx['tenant'], fn () => Refund::query()->sole());
    expect($current->status)->toBe(RefundStatus::FAILED)
        ->and($current->next_attempt_at)->not->toBeNull();
});
