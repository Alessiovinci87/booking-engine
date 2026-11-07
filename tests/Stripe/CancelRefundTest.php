<?php

use App\Domain\Payments\Enums\RefundStatus;
use App\Domain\Payments\Models\Refund;
use App\Domain\Payments\RefundService;
use App\Domain\Reservations\CancellationRefund;
use App\Domain\Reservations\Enums\ReservationStatus;
use App\Domain\Tenancy\Models\OutboxMessage;

/* AC9 con Stripe in modalità test: cancellazione con rimborso reale, date libere subito. */

beforeEach(fn () => $this->account = liveStripe());

it('cancellazione: date libere, rimborso creato su Stripe e completato', function () {
    $fx = liveTenant($this->account, 'INSTANT');
    $reservation = startCheckout($fx);
    livePay($fx, $reservation);
    $confirmed = reloadReservation($fx, $reservation->id);

    inTenant($fx['tenant'], fn () => machine()->cancel($confirmed, CancellationRefund::for($confirmed, null), null, null));

    $refund = inTenant($fx['tenant'], fn () => Refund::query()->sole());
    expect(reloadReservation($fx, $reservation->id)->status)->toBe(ReservationStatus::CANCELLED)
        ->and(occupancyViolations($fx['tenant']))->toBe([])
        ->and($refund->stripe_refund_id)->toStartWith('re_');

    if ($refund->status === RefundStatus::PENDING) {
        app(RefundService::class)->reconcile($fx['tenant'], $refund->id, $refund->stripe_refund_id);
    }

    expect(inTenant($fx['tenant'], fn () => Refund::query()->sole())->status)->toBe(RefundStatus::SUCCEEDED)
        ->and(inTenant($fx['tenant'], fn () => OutboxMessage::query()->where('type', 'reservation.refund_completed')->count()))->toBe(1);
});
