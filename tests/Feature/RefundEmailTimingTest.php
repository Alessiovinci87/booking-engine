<?php

use App\Domain\Payments\Enums\RefundStatus;
use App\Domain\Payments\Models\Refund;
use App\Domain\Reservations\CancellationRefund;
use App\Domain\Reservations\Enums\ReservationStatus;
use App\Domain\Tenancy\Models\OutboxMessage;
use Illuminate\Support\Carbon;

/*
 * I20, AC9 (02, 05 "Cancellazioni e rimborsi"): la cancellazione libera subito le date e comunica il
 * rimborso come "avviato"; l'email "rimborso completato" parte solo quando Stripe conferma
 * (refund.updated riletto via API), mai prima e mai per un rimborso fallito. Stripe simulato.
 */

beforeEach(function () {
    Carbon::setTestNow('2027-06-01 10:00:00');
    $this->sim = stripeSimulator();
    $this->fx = bookableTenant('INSTANT');
    $this->reservation = stripeGuestPays($this, $this->sim, $this->fx, startCheckout($this->fx));
});
afterEach(function () {
    resetStripeHttp();
    Carbon::setTestNow();
});

function outboxCount(array $fx, string $type): int
{
    return inTenant($fx['tenant'], fn () => OutboxMessage::query()->where('type', $type)->count());
}

it('rimborso in attesa: date libere, email "avviato", nessuna email "completato" fino a refund.updated', function () {
    $this->sim->refundStatus = 'pending';
    inTenant($this->fx['tenant'], fn () => machine()->cancel($this->reservation, CancellationRefund::for($this->reservation, null), null, null));

    $refund = inTenant($this->fx['tenant'], fn () => Refund::query()->sole());
    expect(reloadReservation($this->fx, $this->reservation->id)->status)->toBe(ReservationStatus::CANCELLED)
        ->and(occupancyViolations($this->fx['tenant']))->toBe([])
        ->and($refund->status)->toBe(RefundStatus::PENDING)
        ->and($refund->stripe_refund_id)->toStartWith('re_test_')
        ->and(outboxCount($this->fx, 'reservation.cancelled'))->toBe(1)
        ->and(outboxCount($this->fx, 'reservation.refund_completed'))->toBe(0);

    // Evento con contenuto "succeeded" ma su Stripe è ancora in attesa: nessuna email.
    $forged = $this->sim->refunds[$refund->stripe_refund_id];
    $forged['status'] = 'succeeded';
    postStripeWebhook($this, $this->sim->event('refund.updated', $forged, $this->fx['account']))->assertOk();
    expect(outboxCount($this->fx, 'reservation.refund_completed'))->toBe(0);

    $settled = $this->sim->settleRefund($refund->stripe_refund_id, 'succeeded');
    postStripeWebhook($this, $this->sim->event('refund.updated', $settled, $this->fx['account']))->assertOk();
    postStripeWebhook($this, $this->sim->event('charge.refunded', ['id' => 'ch_x', 'object' => 'charge', 'payment_intent' => latestPayment($this->fx, $this->reservation->id)->payment_intent_id, 'metadata' => []], $this->fx['account']))->assertOk();

    expect(inTenant($this->fx['tenant'], fn () => Refund::query()->sole())->status)->toBe(RefundStatus::SUCCEEDED)
        ->and(outboxCount($this->fx, 'reservation.refund_completed'))->toBe(1);
});

it('rimborso fallito in modo asincrono: nessuna email "completato", nuovo tentativo programmato', function () {
    $this->sim->refundStatus = 'pending';
    inTenant($this->fx['tenant'], fn () => machine()->cancel($this->reservation, CancellationRefund::for($this->reservation, null), null, null));
    $refund = inTenant($this->fx['tenant'], fn () => Refund::query()->sole());

    $failed = $this->sim->settleRefund($refund->stripe_refund_id, 'failed');
    postStripeWebhook($this, $this->sim->event('refund.updated', $failed, $this->fx['account']))->assertOk();

    $current = inTenant($this->fx['tenant'], fn () => Refund::query()->sole());
    expect($current->status)->toBe(RefundStatus::FAILED)
        ->and($current->next_attempt_at)->not->toBeNull()
        ->and(outboxCount($this->fx, 'reservation.refund_completed'))->toBe(0)
        ->and(reloadReservation($this->fx, $this->reservation->id)->status)->toBe(ReservationStatus::CANCELLED);
});

it('rimborso riuscito subito: email "completato" una sola volta', function () {
    inTenant($this->fx['tenant'], fn () => machine()->cancel($this->reservation, CancellationRefund::for($this->reservation, null), null, null));

    expect(inTenant($this->fx['tenant'], fn () => Refund::query()->sole())->status)->toBe(RefundStatus::SUCCEEDED)
        ->and(outboxCount($this->fx, 'reservation.refund_completed'))->toBe(1);
});
