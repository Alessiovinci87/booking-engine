<?php

use App\Domain\Payments\Enums\PaymentStatus;
use App\Domain\Payments\Enums\RefundStatus;
use App\Domain\Payments\Gateway\RefundResultStatus;
use App\Domain\Payments\Models\Refund;
use App\Domain\Payments\RefundService;
use App\Domain\Reservations\CancellationRefund;
use App\Domain\Tenancy\Models\OutboxMessage;

/*
 * AC19 e ADR-0012 punto 23 con Stripe in modalità test: pm_card_refundFail (carta 4000 0000 0000
 * 5126) rende il rimborso fallito in modo asincrono. Stripe documenta che il rimborso può risultare
 * prima "succeeded" e poi "failed": il test copre entrambi i percorsi.
 * - Rimborso ancora PENDING alla rilettura: FAILED con nuovo tentativo programmato (05).
 * - Rimborso già SUCCEEDED: l'evento sul rimborso, riletto via API, lo porta a FAILED (SUCCEEDED →
 *   FAILED solo su comunicazione di Stripe), senza tentativi automatici, con allerta ed email al
 *   gestore e nessuna email nuova all'ospite.
 */

beforeEach(fn () => $this->account = liveStripe());

it('rimborso fallito su Stripe → FAILED (da PENDING con nuovo tentativo, da SUCCEEDED con allerta al gestore)', function () {
    $fx = liveTenant($this->account, 'INSTANT');
    $reservation = startCheckout($fx);
    livePay($fx, $reservation, 'pm_card_refundFail');
    $confirmed = reloadReservation($fx, $reservation->id);

    inTenant($fx['tenant'], fn () => machine()->cancel($confirmed, CancellationRefund::for($confirmed, null), null, null));
    $refund = inTenant($fx['tenant'], fn () => Refund::query()->sole());
    $initial = $refund->status;
    expect($initial)->toBeIn([RefundStatus::PENDING, RefundStatus::SUCCEEDED]);

    $deadline = time() + 60;
    do {
        $state = liveGateway()->retrieveRefund($fx['account'], (string) $refund->stripe_refund_id);
        if ($state->status === RefundResultStatus::FAILED) {
            break;
        }
        usleep(2_000_000);
    } while (time() < $deadline);

    expect($state->status)->toBe(RefundResultStatus::FAILED);

    // Come StripeEventProcessor per refund.updated / refund.failed: rilettura via API.
    app(RefundService::class)->reconcile($fx['tenant'], $refund->id, $refund->stripe_refund_id, allowReversal: true);

    $current = inTenant($fx['tenant'], fn () => Refund::query()->sole());
    $outbox = fn (string $type): int => inTenant($fx['tenant'], fn () => OutboxMessage::query()->where('type', $type)->count());
    expect($current->status)->toBe(RefundStatus::FAILED);

    if ($initial === RefundStatus::SUCCEEDED) {
        expect($current->next_attempt_at)->toBeNull()
            ->and($outbox('reservation.refund_reversed'))->toBe(1)
            ->and($outbox('reservation.refund_completed'))->toBe(1)
            ->and(latestPayment($fx, $reservation->id)->status)->toBe(PaymentStatus::SUCCEEDED);
    } else {
        expect($current->next_attempt_at)->not->toBeNull()
            ->and($outbox('reservation.refund_completed'))->toBe(0);
    }
});
