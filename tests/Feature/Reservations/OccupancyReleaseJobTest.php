<?php

use App\Domain\Inventory\InventoryService;
use App\Domain\Payments\CheckoutService;
use App\Domain\Payments\Enums\PaymentStatus;
use App\Domain\Payments\Gateway\Fake\FakeCustomerAction;
use App\Domain\Payments\Gateway\Fake\FakeFailure;
use App\Domain\Payments\Gateway\Fake\FakeOperation;
use App\Domain\Reservations\Enums\ReservationStatus;
use App\Domain\Reservations\Jobs\DispatchOccupancyReleaseJob;
use App\Domain\Reservations\Jobs\OccupancyReleaseJob;
use App\Domain\Reservations\StateMachines\ReservationStateMachine;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;

/*
 * 3.6: job di rilascio delle occupazioni (05 "Rilascio delle occupazioni con sessione", 03 T5, T6,
 * T11, T16) con il gateway finto. Il dispatcher legge gli ID dei tenant con app_admin_ops e accoda un
 * job per tenant (08 "Job cross-tenant").
 */

beforeEach(fn () => Carbon::setTestNow('2027-06-01 10:00:00'));
afterEach(fn () => Carbon::setTestNow());

function runRelease(array $fx): void
{
    (new OccupancyReleaseJob($fx['tenant']))->handle(app(TenantContext::class), app(InventoryService::class),
        app(CheckoutService::class), app(ReservationStateMachine::class));
}

it('T5: checkout scaduto → sessione chiusa sul fornitore, prenotazione e pagamento EXPIRED, date libere', function () {
    $fx = bookableTenant('INSTANT');
    $r = startCheckout($fx);

    Carbon::setTestNow(now()->addMinutes(20));
    runRelease($fx);
    expect(reloadReservation($fx, $r->id)->status)->toBe(ReservationStatus::AWAITING_PAYMENT)
        ->and(fakeGateway()->calls(FakeOperation::EXPIRE))->toHaveCount(0);

    Carbon::setTestNow(now()->addMinutes(20));
    runRelease($fx);

    expect(reloadReservation($fx, $r->id)->status)->toBe(ReservationStatus::EXPIRED)
        ->and(latestPayment($fx, $r->id)->status)->toBe(PaymentStatus::EXPIRED)
        ->and(fakeGateway()->calls(FakeOperation::EXPIRE))->toHaveCount(1)
        ->and(occupancyViolations($fx['tenant']))->toBe([]);

    // Le date sono di nuovo vendibili.
    expect(startCheckout($fx)->status)->toBe(ReservationStatus::AWAITING_PAYMENT);
});

it('T6: sessione già completata alla scadenza → conferma invece del rilascio', function () {
    $fx = bookableTenant('INSTANT');
    $r = startCheckout($fx);
    // L'ospite ha pagato, ma l'evento non è arrivato.
    fakeGateway()->simulateCustomer((string) latestPayment($fx, $r->id)->checkout_session_id, FakeCustomerAction::PAY);

    Carbon::setTestNow(now()->addMinutes(40));
    runRelease($fx);

    expect(reloadReservation($fx, $r->id)->status)->toBe(ReservationStatus::CONFIRMED)
        ->and(latestPayment($fx, $r->id)->status)->toBe(PaymentStatus::SUCCEEDED)
        ->and(occupancyViolations($fx['tenant']))->toBe([]);
});

it('fornitore irraggiungibile → nessun rilascio, nuovo tentativo e avviso oltre 30 minuti', function () {
    $fx = bookableTenant('INSTANT');
    $r = startCheckout($fx);
    Log::spy();

    Carbon::setTestNow(now()->addMinutes(40));
    fakeGateway()->failNext(FakeOperation::EXPIRE, FakeFailure::TIMEOUT);
    runRelease($fx);
    expect(reloadReservation($fx, $r->id)->status)->toBe(ReservationStatus::AWAITING_PAYMENT);
    Log::shouldNotHaveReceived('error');

    Carbon::setTestNow(now()->addMinutes(40));
    fakeGateway()->failNext(FakeOperation::EXPIRE, FakeFailure::TIMEOUT);
    runRelease($fx);
    expect(reloadReservation($fx, $r->id)->status)->toBe(ReservationStatus::AWAITING_PAYMENT)
        ->and(occupancyViolations($fx['tenant']))->toBe([]);
    Log::shouldHaveReceived('error')->withArgs(fn (string $message, array $context) => $message === 'occupancy_release.overdue'
        && $context['reservation_id'] === $r->id && ! str_contains(json_encode($context), 'example.test'))->once();

    runRelease($fx);
    expect(reloadReservation($fx, $r->id)->status)->toBe(ReservationStatus::EXPIRED);
});

it('T11: richiesta non decisa entro approval_deadline → EXPIRED e annullo dell\'autorizzazione', function () {
    $fx = bookableTenant('REQUEST');
    $r = reservationIn($fx, ReservationStatus::AWAITING_APPROVAL);

    Carbon::setTestNow(now()->addHours(11));
    runRelease($fx);
    expect(reloadReservation($fx, $r->id)->status)->toBe(ReservationStatus::AWAITING_APPROVAL);

    Carbon::setTestNow(now()->addHours(2));
    runRelease($fx);

    expect(reloadReservation($fx, $r->id)->status)->toBe(ReservationStatus::EXPIRED)
        ->and(fakeGateway()->calls(FakeOperation::CANCEL))->toHaveCount(1)
        ->and(latestPayment($fx, $r->id)->status)->toBe(PaymentStatus::AUTHORIZATION_CANCELLED)
        ->and(occupancyViolations($fx['tenant']))->toBe([]);
});

it('T16: capture fallita senza decisione entro decision_deadline → PAYMENT_FAILED', function () {
    $fx = bookableTenant('REQUEST');
    $r = reservationIn($fx, ReservationStatus::CAPTURE_FAILED);

    Carbon::setTestNow(now()->addHours(23));
    runRelease($fx);
    expect(reloadReservation($fx, $r->id)->status)->toBe(ReservationStatus::CAPTURE_FAILED);

    Carbon::setTestNow(now()->addHours(2));
    runRelease($fx);

    expect(reloadReservation($fx, $r->id)->status)->toBe(ReservationStatus::PAYMENT_FAILED)
        ->and(occupancyViolations($fx['tenant']))->toBe([]);
});

it('link di recupero scaduto → EXPIRED alla recovery_deadline + 5 minuti', function () {
    $fx = bookableTenant('REQUEST');
    $r = reservationIn($fx, ReservationStatus::CAPTURE_FAILED);
    app(CheckoutService::class)->sendRecoveryLink($fx['tenant'], $r->id, 2);

    Carbon::setTestNow(now()->addHours(2)->addMinutes(3));
    runRelease($fx);
    expect(reloadReservation($fx, $r->id)->status)->toBe(ReservationStatus::AWAITING_PAYMENT);

    Carbon::setTestNow(now()->addMinutes(5));
    runRelease($fx);
    expect(reloadReservation($fx, $r->id)->status)->toBe(ReservationStatus::EXPIRED)
        ->and(occupancyViolations($fx['tenant']))->toBe([]);
});

it('il dispatcher legge gli ID dei tenant con app_admin_ops e accoda un job per tenant', function () {
    $connection = DB::connection(config('payments.release_dispatcher_connection'));
    expect($connection->selectOne('select current_user as u')->u)->toBe('app_admin_ops');

    // Dati confermati: la connessione del dispatcher non vede la transazione del test.
    $expired = committedTenantWithUnit();
    $fresh = committedTenantWithUnit();

    try {
        foreach ([[$expired, '2027-06-01 09:00:00'], [$fresh, '2027-06-01 11:00:00']] as [$t, $expiresAt]) {
            $db = DB::connection(committedConnection('pgsql_migrator', $t['tenant']));
            $guest = uuid7();
            $db->table('guests')->insert(['id' => $guest, 'tenant_id' => $t['tenant'], 'first_name' => 'O', 'last_name' => 'I', 'email' => 'o@example.test',
                'phone' => '+390000000000', 'country_code' => 'IT', 'created_at' => now(), 'updated_at' => now()]);
            $reservation = reservationRow($t['tenant'], $t['unit'], $guest, ['status' => 'AWAITING_PAYMENT', 'confirmation_kind' => null, 'channel' => 'DIRECT_WEB']);
            $db->table('reservations')->insert($reservation);
            $db->table('inventory_occupancies')->insert([
                'id' => uuid7(), 'tenant_id' => $t['tenant'], 'unit_id' => $t['unit'], 'stay' => $reservation['stay'], 'kind' => 'RESERVATION',
                'phase' => 'TEMPORARY', 'temp_reason' => 'CHECKOUT', 'expires_at' => $expiresAt, 'reservation_id' => $reservation['id'],
                'status' => 'ACTIVE', 'ics_uid' => uuid7().'@test', 'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        Queue::fake();
        (new DispatchOccupancyReleaseJob)->handle(app('db'));

        Queue::assertPushed(OccupancyReleaseJob::class, fn (OccupancyReleaseJob $job) => $job->tenantId === $expired['tenant']);
        Queue::assertNotPushed(OccupancyReleaseJob::class, fn (OccupancyReleaseJob $job) => $job->tenantId === $fresh['tenant']);
    } finally {
        deleteCommittedTenant($expired['tenant']);
        deleteCommittedTenant($fresh['tenant']);
    }
});

it('è pianificato ogni minuto senza sovrapposizioni', function () {
    $event = collect(app(Schedule::class)->events())->first(fn ($e) => $e->description === 'occupancies:release');

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('* * * * *')
        ->and($event->withoutOverlapping)->toBeTrue();
});
