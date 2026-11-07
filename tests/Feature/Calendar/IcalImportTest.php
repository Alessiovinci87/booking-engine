<?php

use App\Domain\Calendar\Enums\EchoKind;
use App\Domain\Calendar\Enums\ExternalEventStatus;
use App\Domain\Calendar\Enums\SyncRunOutcome;
use App\Domain\Calendar\Http\RawResponse;
use App\Domain\Calendar\IcalImportService;
use App\Domain\Calendar\Jobs\DispatchCalendarSyncJob;
use App\Domain\Calendar\Jobs\SyncExternalCalendarJob;
use App\Domain\Calendar\StateMachines\ExternalCalendarStateMachine;
use App\Domain\Inventory\InventoryService;
use App\Domain\Reservations\StayRange;
use App\Domain\Tenancy\Models\AuditLog;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

/*
 * 5.2 — IcalImportService (06 "Import"): eventi nuovi, modificati con audit, richieste condizionali,
 * 304, eco confermata dal dominio, nessun dato personale salvato, date bloccate per la disponibilità,
 * prossimo giro con jitter, dispatcher ogni minuto.
 */

it('importa un feed Booking: eventi ACTIVE, date non vendibili, prossimo giro tra 10 min ± 90 s', function () {
    $fx = calendarFixture();
    $fx['http']->responses[$fx['url']] = icsResponse(otaIcs('booking', [
        ['uid' => 'b1@booking.com', 'in' => futureDay(10), 'out' => futureDay(13)],
        ['uid' => 'b2@booking.com', 'in' => futureDay(20), 'out' => futureDay(22)],
    ]), ['etag' => '"v1"']);

    $run = syncCalendar($fx);
    $events = calendarEvents($fx);
    $calendar = freshCalendar($fx);

    expect($run->outcome)->toBe(SyncRunOutcome::SUCCESS)
        ->and($run->events_seen)->toBe(2)->and($run->events_added)->toBe(2)
        ->and($events)->toHaveCount(2)
        ->and($events['b1@booking.com']->status)->toBe(ExternalEventStatus::ACTIVE)
        ->and((string) $events['b1@booking.com']->stay)->toBe('['.futureDay(10).','.futureDay(13).')')
        ->and($events['b1@booking.com']->echo_kind)->toBe(EchoKind::NONE)
        ->and($calendar->etag)->toBe('"v1"')
        ->and($calendar->consecutive_failures)->toBe(0)
        ->and($calendar->last_success_at)->not->toBeNull();

    $seconds = CarbonImmutable::now()->diffInSeconds($calendar->next_poll_at);
    expect($seconds)->toBeGreaterThanOrEqual(600 - 90 - 5)->toBeLessThanOrEqual(600 + 90 + 5);

    $blocked = inTenant($fx['tenant'], fn () => InventoryService::externalBlocksQuery($fx['unit'], StayRange::of(futureDay(11), futureDay(12)))->exists());
    expect($blocked)->toBeTrue();
});

it('Airbnb: SUMMARY "Reserved" conservato, DESCRIPTION con dati della prenotazione mai salvata', function () {
    $fx = calendarFixture('AIRBNB');
    $fx['http']->responses[$fx['url']] = icsResponse(otaIcs('airbnb', [['uid' => 'a1@airbnb.com', 'in' => futureDay(5), 'out' => futureDay(8)]]));

    syncCalendar($fx);

    $row = inTenant($fx['tenant'], fn () => DB::table('external_calendar_events')->where('uid', 'a1@airbnb.com')->first());
    expect($row->summary_redacted)->toBe('Reserved')
        ->and(json_encode($row))->not->toContain('HMABC')->not->toContain('1234');
});

it('richieste condizionali con ETag e Last-Modified; 304 → NOT_MODIFIED senza modifiche', function () {
    $fx = calendarFixture();
    $fx['http']->responses[$fx['url']] = icsResponse(otaIcs('booking', [['uid' => 'b1@booking.com', 'in' => futureDay(3), 'out' => futureDay(5)]]), [
        'etag' => '"abc"', 'last-modified' => 'Tue, 01 Jun 2027 10:00:00 GMT',
    ]);
    syncCalendar($fx);

    $fx['http']->responses[$fx['url']] = new RawResponse(304, ['etag' => '"abc"'], '');
    $run = syncCalendar($fx);

    expect($fx['http']->calls[1]['headers'])->toBe(['If-None-Match' => '"abc"', 'If-Modified-Since' => 'Tue, 01 Jun 2027 10:00:00 GMT'])
        ->and($run->outcome)->toBe(SyncRunOutcome::NOT_MODIFIED)
        ->and(calendarEvents($fx)['b1@booking.com']->status)->toBe(ExternalEventStatus::ACTIVE);
});

it('date cambiate → aggiornate con audit senza dati personali; evento noto → solo last_seen_at', function () {
    $fx = calendarFixture();
    $fx['http']->responses[$fx['url']] = icsResponse(otaIcs('booking', [
        ['uid' => 'b1@booking.com', 'in' => futureDay(3), 'out' => futureDay(5)],
        ['uid' => 'b2@booking.com', 'in' => futureDay(9), 'out' => futureDay(10)],
    ]));
    syncCalendar($fx);
    $before = calendarEvents($fx);

    $this->travel(11)->minutes();
    $fx['http']->responses[$fx['url']] = icsResponse(otaIcs('booking', [
        ['uid' => 'b1@booking.com', 'in' => futureDay(4), 'out' => futureDay(7)],
        ['uid' => 'b2@booking.com', 'in' => futureDay(9), 'out' => futureDay(10)],
    ]));
    $run = syncCalendar($fx);
    $after = calendarEvents($fx);

    $audit = inTenant($fx['tenant'], fn () => AuditLog::query()->where('action', 'external_event.dates_changed')->get());

    expect($run->events_updated)->toBe(1)
        ->and((string) $after['b1@booking.com']->stay)->toBe('['.futureDay(4).','.futureDay(7).')')
        ->and($after['b2@booking.com']->last_seen_at->greaterThan($before['b2@booking.com']->last_seen_at))->toBeTrue()
        ->and($audit)->toHaveCount(1)
        ->and($audit[0]->changes['to'])->toBe(['check_in' => futureDay(4), 'check_out' => futureDay(7)]);
});

it('UID con il nostro dominio → CONFIRMED_ECHO con echo_of_ics_uid', function () {
    $fx = calendarFixture();
    $ours = 'occ-'.uuid7().'@'.InventoryService::icsDomain();
    $fx['http']->responses[$fx['url']] = icsResponse(otaIcs('booking', [['uid' => $ours, 'in' => futureDay(3), 'out' => futureDay(5)]]));

    syncCalendar($fx);
    $event = calendarEvents($fx)[$ours];

    expect($event->echo_kind)->toBe(EchoKind::CONFIRMED_ECHO)->and($event->echo_of_ics_uid)->toBe($ours)
        ->and($event->status)->toBe(ExternalEventStatus::ACTIVE);
});

it('evento rimosso che ricompare torna ACTIVE', function () {
    $fx = calendarFixture();
    $ics = otaIcs('booking', [['uid' => 'b1@booking.com', 'in' => futureDay(3), 'out' => futureDay(5)]]);
    $empty = otaIcs('booking', []);

    $fx['http']->responses[$fx['url']] = icsResponse($ics);
    syncCalendar($fx);
    $fx['http']->responses[$fx['url']] = icsResponse($empty);
    syncCalendar($fx);
    $this->travel(11)->minutes();
    syncCalendar($fx);
    expect(calendarEvents($fx)['b1@booking.com']->status)->toBe(ExternalEventStatus::REMOVED);

    $fx['http']->responses[$fx['url']] = icsResponse($ics);
    syncCalendar($fx);
    expect(calendarEvents($fx)['b1@booking.com']->status)->toBe(ExternalEventStatus::ACTIVE);
});

it('un calendario disattivato non si sincronizza', function () {
    $fx = calendarFixture();
    inTenant($fx['tenant'], fn () => app(ExternalCalendarStateMachine::class)->disable(freshCalendar($fx)));

    expect(syncCalendar($fx))->toBeNull()->and($fx['http']->calls)->toBe([]);
});

it('backoff 10, 20, 40, 60 minuti', function () {
    expect(array_map(fn ($n) => IcalImportService::backoffMinutes($n), [1, 2, 3, 4, 5, 10]))->toBe([10, 20, 40, 60, 60, 60]);
});

it('il dispatcher (app_admin_ops) accoda un job per ogni calendario scaduto o mai sincronizzato, non i disattivati né i dimostrativi', function () {
    Queue::fake();
    $fx = committedTenantWithUnit();
    $db = DB::connection(committedConnection('pgsql_migrator', $fx['tenant']));
    $ids = [uuid7(), uuid7(), uuid7(), uuid7(), uuid7()];

    try {
        // L'ultimo è un calendario dimostrativo del seeder (ADR-0013 punto 44): mai nel polling.
        foreach ([[null, 'ACTIVE', false], [now()->subMinute(), 'DEGRADED', false], [now()->addMinutes(5), 'ACTIVE', false], [null, 'DISABLED', false], [null, 'ACTIVE', true]] as $i => [$next, $status, $demo]) {
            $db->table('external_calendars')->insert([
                'id' => $ids[$i], 'tenant_id' => $fx['tenant'], 'unit_id' => $fx['unit'], 'source' => 'BOOKING',
                'url_encrypted' => 'x', 'status' => $status, 'next_poll_at' => $next, 'demo' => $demo, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        (new DispatchCalendarSyncJob)->handle(app('db'));

        $queued = [];
        Queue::assertPushed(SyncExternalCalendarJob::class, function (SyncExternalCalendarJob $job) use (&$queued, $fx) {
            if ($job->tenantId === $fx['tenant']) {
                $queued[] = $job->calendarId;
            }

            return true;
        });

        sort($queued);
        $expected = [$ids[0], $ids[1]];
        sort($expected);
        expect($queued)->toBe($expected);
    } finally {
        $db->table('external_calendars')->where('tenant_id', $fx['tenant'])->delete();
        deleteCommittedTenant($fx['tenant']);
    }
});
