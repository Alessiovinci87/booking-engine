<?php

use App\Domain\Communications\Alerts\AlertSignal;
use App\Domain\Communications\Alerts\Jobs\CheckQueueDelayJob;
use App\Domain\Communications\Alerts\Jobs\UnhealthyCalendarsSummaryJob;
use App\Domain\Communications\Alerts\OperationalAlerts;
use App\Domain\Reservations\Outbox\ReservationEmail;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;

/*
 * 6.5 — Allerte operative di 08 (ADR-0014): log al livello del segnale ed email agli indirizzi della
 * piattaforma per i segnali "push", al più una per finestra; solo identificativi. Controlli
 * periodici: coda in ritardo (outbox) e riepilogo giornaliero dei calendari UNHEALTHY.
 */

beforeEach(function () {
    Mail::fake();
    config(['alerts.emails' => 'ops@example.test, reperibile@example.test', 'alerts.throttle_minutes' => 60]);
    $this->logged = [];
    Event::listen(MessageLogged::class, function (MessageLogged $e) {
        $this->logged[] = $e;
    });
});

function loggedSignal(array $logged, AlertSignal $signal): ?MessageLogged
{
    return collect($logged)->first(fn (MessageLogged $e) => $e->message === $signal->value);
}

it('log al livello del segnale ed email alla piattaforma, una sola volta nella finestra', function () {
    $alerts = app(OperationalAlerts::class);
    $alerts->raise(AlertSignal::I15_VIOLATION, ['tenant_id' => 't-1', 'reservation_id' => 'r-1']);
    $alerts->raise(AlertSignal::I15_VIOLATION, ['tenant_id' => 't-1', 'reservation_id' => 'r-1']);

    expect(loggedSignal($this->logged, AlertSignal::I15_VIOLATION)?->level)->toBe('critical')
        ->and(collect($this->logged)->where('message', AlertSignal::I15_VIOLATION->value)->count())->toBe(2);

    Mail::assertSent(ReservationEmail::class, 2); // due destinatari, una volta sola
    Mail::assertSent(ReservationEmail::class, fn (ReservationEmail $m) => $m->hasTo('ops@example.test')
        && str_contains($m->subjectLine, '[Allerta]')
        && str_contains(implode("\n", $m->lines), 'reservation_id: r-1')
        && $m->category === 'ops.invariant.i15_violation');
});

it('senza indirizzi configurati resta solo il log; il rimborso fallito definitivo non scrive alla piattaforma', function () {
    config(['alerts.emails' => '']);
    app(OperationalAlerts::class)->raise(AlertSignal::CAPTURING_OVERDUE, ['payment_id' => 'p-1']);
    config(['alerts.emails' => 'ops@example.test']);
    app(OperationalAlerts::class)->raise(AlertSignal::REFUND_FAILED_FINAL, ['refund_id' => 'f-1']);

    expect(loggedSignal($this->logged, AlertSignal::CAPTURING_OVERDUE)?->level)->toBe('error')
        ->and(loggedSignal($this->logged, AlertSignal::REFUND_FAILED_FINAL))->not->toBeNull();
    Mail::assertNothingSent();
});

it('l\'email parte solo dopo il commit: un rollback non la invia', function () {
    try {
        DB::transaction(function () {
            app(OperationalAlerts::class)->raise(AlertSignal::REFUND_REVERSED, ['refund_id' => 'f-2']);
            throw new RuntimeException('rollback');
        });
    } catch (RuntimeException) {
    }

    Mail::assertNothingSent();
    expect(loggedSignal($this->logged, AlertSignal::REFUND_REVERSED)?->level)->toBe('critical');
});

it('coda in ritardo: messaggi dell\'outbox pronti da oltre 5 minuti; calendari UNHEALTHY nel riepilogo giornaliero', function () {
    $fx = committedTenantWithUnit(['booking_mode' => 'REQUEST']);
    $db = DB::connection(committedConnection('pgsql_migrator', $fx['tenant']));

    try {
        $db->table('outbox_messages')->insert([
            'id' => uuid7(), 'tenant_id' => $fx['tenant'], 'type' => 'reservation.confirmed', 'payload' => '{}',
            'dedupe_key' => 'alert-test', 'status' => 'PENDING', 'available_at' => now()->subMinutes(12), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $db->table('external_calendars')->insert([
            'id' => uuid7(), 'tenant_id' => $fx['tenant'], 'unit_id' => $fx['unit'], 'source' => 'AIRBNB',
            'url_encrypted' => 'x', 'status' => 'UNHEALTHY', 'created_at' => now(), 'updated_at' => now(),
        ]);

        app()->call([new CheckQueueDelayJob, 'handle']);
        app()->call([new UnhealthyCalendarsSummaryJob, 'handle']);

        $queue = loggedSignal($this->logged, AlertSignal::QUEUE_DELAYED);
        $calendars = loggedSignal($this->logged, AlertSignal::CALENDARS_UNHEALTHY_DAILY);
        expect($queue?->level)->toBe('error')
            ->and($queue->context['delayed_messages'])->toBeGreaterThanOrEqual(1)
            ->and($calendars?->level)->toBe('warning')
            ->and($calendars->context['tenant '.$fx['tenant']])->toBe(1);
        Mail::assertSent(ReservationEmail::class, fn (ReservationEmail $m) => $m->category === 'ops.alert.queue_delayed');
    } finally {
        $db->table('outbox_messages')->where('tenant_id', $fx['tenant'])->delete();
        $db->table('external_calendars')->where('tenant_id', $fx['tenant'])->delete();
        deleteCommittedTenant($fx['tenant']);
    }
});

it('i segnali esistenti passano dalle allerte: occupazione non rilasciata e incoerenza I15 restano nei log attesi', function () {
    expect(AlertSignal::OCCUPANCY_RELEASE_OVERDUE->value)->toBe('occupancy_release.overdue')
        ->and(AlertSignal::I15_VIOLATION->value)->toBe('invariant.i15_violation')
        ->and(AlertSignal::OCCUPANCY_RELEASE_OVERDUE->emailsPlatform())->toBeTrue();
});
