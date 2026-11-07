<?php

use App\Domain\Payments\CheckoutService;
use App\Domain\Payments\Enums\PaymentPurpose;
use App\Domain\Payments\Enums\PaymentStatus;
use App\Domain\Reservations\Enums\ReservationStatus;
use App\Domain\Reservations\Jobs\OccupancyReleaseJob;
use App\Domain\Reservations\Outbox\SendReservationEmail;
use App\Domain\Tenancy\Models\OutboxMessage;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;

/*
 * AC14 (01, 03 T13–T16, 05 flusso R) con Stripe simulato: la capture fallita occupa le date per 24 h;
 * il link di recupero crea un nuovo Payment RECOVERY con capture automatica e sessione che scade a
 * recovery_deadline; pagato → CONFIRMED; scaduto → EXPIRED; nessuna decisione → PAYMENT_FAILED a
 * 24 h. Il gestore riceve un promemoria a 12 h solo se non ha ancora deciso.
 */

beforeEach(function () {
    Carbon::setTestNow('2027-06-01 10:00:00');
    $this->sim = stripeSimulator();
    $this->fx = bookableTenant('REQUEST');
    $reservation = stripeGuestPays($this, $this->sim, $this->fx, startCheckout($this->fx));
    $this->sim->declineNextCapture = 'card_declined';
    inTenant($this->fx['tenant'], fn () => machine()->approve($reservation));
    $this->reservation = reloadReservation($this->fx, $reservation->id);
});
afterEach(function () {
    resetStripeHttp();
    Carbon::setTestNow();
});

function recoveryLink(array $fx, string $reservationId, int $hours = 24): void
{
    app(CheckoutService::class)->sendRecoveryLink($fx['tenant'], $reservationId, $hours);
}

it('CAPTURE_FAILED occupa le date fino a decision_deadline = ingresso + 24 h', function () {
    expect($this->reservation->status)->toBe(ReservationStatus::CAPTURE_FAILED)
        ->and($this->reservation->decision_deadline->toIso8601String())->toBe('2027-06-02T10:00:00+00:00')
        ->and(occupancyViolations($this->fx['tenant']))->toBe([]);
});

it('il link di recupero crea un Payment RECOVERY con capture automatica e sessione fino a recovery_deadline', function () {
    recoveryLink($this->fx, $this->reservation->id, 6);

    $payment = latestPayment($this->fx, $this->reservation->id);
    $request = $this->sim->http->requestsTo('post', '/v1/checkout/sessions')[1];
    expect(reloadReservation($this->fx, $this->reservation->id)->status)->toBe(ReservationStatus::AWAITING_PAYMENT)
        ->and($payment->purpose)->toBe(PaymentPurpose::RECOVERY)
        ->and($request['idempotency_key'])->toBe('checkout:'.$payment->id)
        ->and($request['params']['payment_intent_data']['capture_method'])->toBe('automatic')
        ->and($request['params']['expires_at'])->toBe(now()->addHours(6)->getTimestamp());
});

it('recupero pagato → CONFIRMED sulla stessa prenotazione', function () {
    recoveryLink($this->fx, $this->reservation->id);

    expect(stripeGuestPays($this, $this->sim, $this->fx, $this->reservation)->status)->toBe(ReservationStatus::CONFIRMED)
        ->and(latestPayment($this->fx, $this->reservation->id)->status)->toBe(PaymentStatus::SUCCEEDED)
        ->and(occupancyViolations($this->fx['tenant']))->toBe([]);
});

it('recupero scaduto → la sessione si chiude su Stripe e la prenotazione è EXPIRED', function () {
    recoveryLink($this->fx, $this->reservation->id, 2);

    Carbon::setTestNow(now()->addHours(2)->addMinutes(6));
    OccupancyReleaseJob::dispatchSync($this->fx['tenant']);

    expect(reloadReservation($this->fx, $this->reservation->id)->status)->toBe(ReservationStatus::EXPIRED)
        ->and(latestPayment($this->fx, $this->reservation->id)->status)->toBe(PaymentStatus::EXPIRED)
        ->and(occupancyViolations($this->fx['tenant']))->toBe([]);
});

it('nessuna decisione entro 24 h → PAYMENT_FAILED e date libere', function () {
    Carbon::setTestNow('2027-06-02 10:00:01');
    OccupancyReleaseJob::dispatchSync($this->fx['tenant']);

    expect(reloadReservation($this->fx, $this->reservation->id)->status)->toBe(ReservationStatus::PAYMENT_FAILED)
        ->and(occupancyViolations($this->fx['tenant']))->toBe([]);
});

it('promemoria al gestore a 12 h, saltato se il gestore ha già deciso', function () {
    Mail::fake();
    $reminder = inTenant($this->fx['tenant'], fn () => OutboxMessage::query()->where('type', 'reservation.capture_failed_reminder')->sole());
    expect($reminder->available_at->toIso8601String())->toBe('2027-06-01T22:00:00+00:00');

    inTenant($this->fx['tenant'], fn () => app(SendReservationEmail::class)->handle($reminder));
    Mail::assertSentCount(1);

    $current = reloadReservation($this->fx, $this->reservation->id);
    inTenant($this->fx['tenant'], fn () => machine()->releaseDates($current));
    inTenant($this->fx['tenant'], fn () => app(SendReservationEmail::class)->handle($reminder));
    Mail::assertSentCount(1);
});
