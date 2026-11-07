<?php

use App\Domain\Payments\Enums\PaymentStatus;
use App\Domain\Payments\Enums\RefundStatus;
use App\Domain\Payments\Jobs\DispatchPaymentReconciliationJob;
use App\Domain\Payments\Jobs\ReconcilePaymentsJob;
use App\Domain\Payments\Models\Refund;
use App\Domain\Reservations\CancellationRefund;
use App\Domain\Reservations\Enums\ReservationStatus;
use App\Domain\Reservations\Models\Reservation;
use App\Domain\Tenancy\Exceptions\BusinessRuleViolation;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;

/*
 * 4.8 — PaymentReconciler (05 "Riconciliazione") con Stripe simulato: dispatcher con app_admin_ops,
 * un job per tenant; sessioni mancanti recuperate con la stessa chiave, CAPTURING chiuso, autorizzazioni
 * annullate lato Stripe, rimborsi in attesa riletti, violazioni di I15 segnalate come allerta P1.
 */

beforeEach(function () {
    Carbon::setTestNow('2027-06-01 10:00:00');
    $this->sim = stripeSimulator();
});
afterEach(function () {
    resetStripeHttp();
    Carbon::setTestNow();
});

it('Payment PENDING senza sessione da oltre 5 minuti: sessione recuperata con la stessa chiave', function () {
    $fx = bookableTenant('INSTANT');
    $this->sim->loseNextCreateResponse = true;

    try {
        startCheckout($fx);
    } catch (BusinessRuleViolation) {
    }

    $reservation = inTenant($fx['tenant'], fn () => Reservation::query()->sole());
    Carbon::setTestNow(now()->addMinutes(6));
    ReconcilePaymentsJob::dispatchSync($fx['tenant']);

    $payment = latestPayment($fx, $reservation->id);
    $keys = array_column($this->sim->http->requestsTo('post', '/v1/checkout/sessions'), 'idempotency_key');
    expect($payment->checkout_session_id)->not->toBeNull()
        ->and(array_unique($keys))->toBe(['checkout:'.$payment->id])
        ->and($this->sim->sessions)->toHaveCount(1)
        ->and(reloadReservation($fx, $reservation->id)->status)->toBe(ReservationStatus::AWAITING_PAYMENT);
});

it('CAPTURING: il giro ogni 5 minuti chiude l\'esito sconosciuto della capture', function () {
    $fx = bookableTenant('REQUEST');
    $reservation = stripeGuestPays($this, $this->sim, $fx, startCheckout($fx));
    $this->sim->http->fail('post', '/v1/payment_intents/.+/capture', 500, 'api_error', times: 1);
    inTenant($fx['tenant'], fn () => machine()->approve($reservation));
    expect(reloadReservation($fx, $reservation->id)->status)->toBe(ReservationStatus::CAPTURING);

    ReconcilePaymentsJob::dispatchSync($fx['tenant'], ReconcilePaymentsJob::CAPTURING);

    expect(reloadReservation($fx, $reservation->id)->status)->toBe(ReservationStatus::CONFIRMED);
});

it('capture riuscita su Stripe ma esito perso: la riconciliazione legge succeeded e conferma (T12)', function () {
    $fx = bookableTenant('REQUEST');
    $reservation = stripeGuestPays($this, $this->sim, $fx, startCheckout($fx));
    $payment = latestPayment($fx, $reservation->id);
    $this->sim->http->fail('post', '/v1/payment_intents/.+/capture', 500, 'api_error', times: 1);
    inTenant($fx['tenant'], fn () => machine()->approve($reservation));
    $this->sim->intents[$payment->payment_intent_id]['status'] = 'succeeded';
    $this->sim->intents[$payment->payment_intent_id]['amount_received'] = $payment->amount_cents;

    ReconcilePaymentsJob::dispatchSync($fx['tenant'], ReconcilePaymentsJob::CAPTURING);

    expect(reloadReservation($fx, $reservation->id)->status)->toBe(ReservationStatus::CONFIRMED)
        ->and(latestPayment($fx, $reservation->id)->captured_cents)->toBe($payment->amount_cents)
        ->and($this->sim->http->requestsTo('post', '/v1/payment_intents/.+/capture'))->toHaveCount(1);
});

it('autorizzazione annullata o scaduta lato Stripe → AUTHORIZATION_CANCELLED', function () {
    $fx = bookableTenant('REQUEST');
    $reservation = stripeGuestPays($this, $this->sim, $fx, startCheckout($fx));
    $payment = latestPayment($fx, $reservation->id);
    $this->sim->intents[$payment->payment_intent_id]['status'] = 'canceled';

    ReconcilePaymentsJob::dispatchSync($fx['tenant']);

    expect(latestPayment($fx, $reservation->id)->status)->toBe(PaymentStatus::AUTHORIZATION_CANCELLED);
});

it('rimborso PENDING da oltre un\'ora: riletto e chiuso', function () {
    $fx = bookableTenant('INSTANT');
    $reservation = stripeGuestPays($this, $this->sim, $fx, startCheckout($fx));
    $this->sim->refundStatus = 'pending';
    inTenant($fx['tenant'], fn () => machine()->cancel($reservation, CancellationRefund::for($reservation, null), null, null));
    $refund = inTenant($fx['tenant'], fn () => Refund::query()->sole());
    $this->sim->settleRefund($refund->stripe_refund_id, 'succeeded');

    Carbon::setTestNow(now()->addMinutes(61));
    ReconcilePaymentsJob::dispatchSync($fx['tenant']);

    expect(inTenant($fx['tenant'], fn () => Refund::query()->sole())->status)->toBe(RefundStatus::SUCCEEDED);
});

it('il dispatcher legge con app_admin_ops: un job per tenant e allerta P1 per le violazioni di I15 con soli ID', function () {
    // Dati confermati: la connessione del dispatcher non vede la transazione del test.
    $t = committedTenantWithUnit();

    try {
        $db = DB::connection(committedConnection('pgsql_migrator', $t['tenant']));
        $guest = uuid7();
        $db->table('guests')->insert(['id' => $guest, 'tenant_id' => $t['tenant'], 'first_name' => 'O', 'last_name' => 'I', 'email' => 'o@example.test',
            'phone' => '+390000000000', 'country_code' => 'IT', 'created_at' => now(), 'updated_at' => now()]);
        // Corruzione volontaria (solo test): prenotazione terminale con occupazione ancora attiva.
        $reservation = reservationRow($t['tenant'], $t['unit'], $guest, ['status' => 'CANCELLED', 'confirmation_kind' => 'ONLINE_PAYMENT', 'channel' => 'DIRECT_WEB']);
        $db->table('reservations')->insert($reservation);
        $db->table('inventory_occupancies')->insert([
            'id' => uuid7(), 'tenant_id' => $t['tenant'], 'unit_id' => $t['unit'], 'stay' => $reservation['stay'], 'kind' => 'RESERVATION',
            'phase' => 'FIRM', 'reservation_id' => $reservation['id'],
            'status' => 'ACTIVE', 'ics_uid' => uuid7().'@test', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $db->table('payments')->insert([
            'id' => uuid7(), 'tenant_id' => $t['tenant'], 'reservation_id' => $reservation['id'], 'purpose' => 'DEPOSIT', 'capture_method' => 'MANUAL', 'status' => 'CAPTURE_PENDING',
            'amount_cents' => 10000, 'created_at' => now(), 'updated_at' => now(),
        ]);

        Queue::fake();
        Log::spy();
        (new DispatchPaymentReconciliationJob(ReconcilePaymentsJob::FULL))->handle(app('db'));

        Queue::assertPushed(ReconcilePaymentsJob::class, fn (ReconcilePaymentsJob $job) => $job->tenantId === $t['tenant'] && $job->scope === ReconcilePaymentsJob::FULL);
        Log::shouldHaveReceived('critical')->withArgs(fn (string $message, array $context): bool => $message === 'invariant.i15_violation'
            && $context['reservation_id'] === $reservation['id'] && $context['problem'] === 'active_occupancy_on_terminal')->once();
    } finally {
        deleteCommittedTenant($t['tenant']);
    }
});
