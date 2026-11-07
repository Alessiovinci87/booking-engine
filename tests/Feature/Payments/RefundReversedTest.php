<?php

use App\Domain\Payments\Enums\PaymentStatus;
use App\Domain\Payments\Enums\RefundStatus;
use App\Domain\Payments\Models\Refund;
use App\Domain\Payments\RefundService;
use App\Domain\Reservations\CancellationRefund;
use App\Domain\Reservations\Models\Reservation;
use App\Domain\Reservations\Outbox\ReservationEmail;
use App\Domain\Reservations\Outbox\SendReservationEmail;
use App\Domain\Reservations\ReservationEconomics;
use App\Domain\Tenancy\Models\OutboxMessage;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

/*
 * Decisione dopo la verifica di M4 (ADR-0012 punto 23, 03 sezione 3): un rimborso SUCCEEDED può
 * diventare FAILED solo quando lo comunica Stripe (evento sul rimborso, riletto via API). Effetti:
 * rimborsato del pagamento e refund_status ricalcolati, allerta urgente ed email al gestore, nessuna
 * email automatica all'ospite. Stripe simulato; con Stripe vero: Stripe/RefundFailureTest.
 */

beforeEach(function () {
    Carbon::setTestNow('2027-06-01 10:00:00');
    $this->sim = stripeSimulator();
    $this->fx = bookableTenant('INSTANT');
    $this->reservation = stripeGuestPays($this, $this->sim, $this->fx, startCheckout($this->fx));
    inTenant($this->fx['tenant'], fn () => machine()->cancel($this->reservation, CancellationRefund::for($this->reservation, null), null, null));
    $this->refund = inTenant($this->fx['tenant'], fn () => Refund::query()->sole());
});
afterEach(function () {
    resetStripeHttp();
    Carbon::setTestNow();
});

function reversedOutbox(array $fx, string $type): int
{
    return inTenant($fx['tenant'], fn () => OutboxMessage::query()->where('type', $type)->count());
}

function economicsOf(array $fx, string $reservationId): ReservationEconomics
{
    return inTenant($fx['tenant'], fn () => ReservationEconomics::of(Reservation::query()->findOrFail($reservationId)));
}

it('Stripe dichiara fallito un rimborso riuscito: FAILED, importi ricalcolati, allerta al gestore, niente all\'ospite', function () {
    expect($this->refund->status)->toBe(RefundStatus::SUCCEEDED)
        ->and(latestPayment($this->fx, $this->reservation->id)->status)->toBe(PaymentStatus::REFUNDED)
        ->and(reversedOutbox($this->fx, 'reservation.refund_completed'))->toBe(1);
    $guestMessagesBefore = inTenant($this->fx['tenant'], fn () => OutboxMessage::query()->where('type', 'not like', '%_manager')
        ->whereNotIn('type', ['reservation.refund_reversed', 'reservation.refund_failed'])->count());

    $failed = $this->sim->settleRefund((string) $this->refund->stripe_refund_id, 'failed');
    postStripeWebhook($this, $this->sim->event('refund.updated', $failed, $this->fx['account']))->assertOk();
    postStripeWebhook($this, $this->sim->event('refund.failed', $failed, $this->fx['account']))->assertOk();

    $refund = inTenant($this->fx['tenant'], fn () => Refund::query()->sole());
    expect($refund->status)->toBe(RefundStatus::FAILED)
        ->and($refund->next_attempt_at)->toBeNull()
        ->and($refund->completed_at)->toBeNull()
        ->and(latestPayment($this->fx, $this->reservation->id)->status)->toBe(PaymentStatus::SUCCEEDED)
        ->and(economicsOf($this->fx, $this->reservation->id)->refundedCents)->toBe(0)
        ->and(economicsOf($this->fx, $this->reservation->id)->refundStatus()->value)->toBe('FAILED')
        ->and(reversedOutbox($this->fx, 'reservation.refund_reversed'))->toBe(1)
        ->and(inTenant($this->fx['tenant'], fn () => OutboxMessage::query()->where('type', 'not like', '%_manager')
            ->whereNotIn('type', ['reservation.refund_reversed', 'reservation.refund_failed'])->count()))->toBe($guestMessagesBefore)
        ->and(json_decode((string) inTenant($this->fx['tenant'], fn () => DB::table('audit_log')->where('action', 'refund.failed')->where('subject_id', $refund->id)->value('changes')), true)['reported_by'] ?? null)->toBe('stripe');

    // Email al gestore (contatto della struttura), con il nome dell'ospite: è il titolare del trattamento.
    Mail::fake();
    $message = inTenant($this->fx['tenant'], fn () => OutboxMessage::query()->where('type', 'reservation.refund_reversed')->sole());
    inTenant($this->fx['tenant'], fn () => app(SendReservationEmail::class)->handle($message));
    Mail::assertSent(ReservationEmail::class, fn (ReservationEmail $mail) => ! $mail->hasTo('ada@example.test')
        && str_contains($mail->subjectLine, 'Urgente')
        && str_contains(implode(' ', $mail->lines), 'Contatta l’ospite'));
});

it('un evento con contenuto "failed" non basta: conta la rilettura via API', function () {
    $forged = $this->sim->refunds[$this->refund->stripe_refund_id];
    $forged['status'] = 'failed';
    postStripeWebhook($this, $this->sim->event('refund.updated', $forged, $this->fx['account']))->assertOk();

    expect(inTenant($this->fx['tenant'], fn () => Refund::query()->sole())->status)->toBe(RefundStatus::SUCCEEDED)
        ->and(reversedOutbox($this->fx, 'reservation.refund_reversed'))->toBe(0);
});

it('charge.refunded e la riconciliazione non rileggono i rimborsi già riusciti', function () {
    $this->sim->settleRefund((string) $this->refund->stripe_refund_id, 'failed');
    postStripeWebhook($this, $this->sim->event('charge.refunded', ['id' => 'ch_x', 'object' => 'charge', 'payment_intent' => latestPayment($this->fx, $this->reservation->id)->payment_intent_id, 'metadata' => []], $this->fx['account']))->assertOk();
    expect(app(RefundService::class)->reconcile($this->fx['tenant'], $this->refund->id))->toBe('unchanged');

    expect(inTenant($this->fx['tenant'], fn () => Refund::query()->sole())->status)->toBe(RefundStatus::SUCCEEDED);
});

it('dopo l\'annullamento il gestore può segnarlo come rimborsato fuori piattaforma', function () {
    $failed = $this->sim->settleRefund((string) $this->refund->stripe_refund_id, 'failed');
    postStripeWebhook($this, $this->sim->event('refund.updated', $failed, $this->fx['account']))->assertOk();

    actingAsPanelUser($this, panelUser($this->fx['tenant'])['user'], passwordConfirmed: true);
    panelPost($this, "/api/v1/admin/refunds/{$this->refund->id}/mark-offline", ['note' => 'Bonifico del 2 giugno'])->assertOk();

    expect(inTenant($this->fx['tenant'], fn () => Refund::query()->sole())->status)->toBe(RefundStatus::SUCCEEDED)
        ->and(latestPayment($this->fx, $this->reservation->id)->status)->toBe(PaymentStatus::REFUNDED);
});
