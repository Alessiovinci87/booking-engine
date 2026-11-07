<?php

use App\Domain\Calendar\Enums\ExternalCalendarStatus;
use App\Domain\Calendar\Http\FetchError;
use App\Domain\Calendar\Http\FetchFailed;
use App\Domain\Calendar\Jobs\DispatchSyncHealthJob;
use App\Domain\Calendar\Jobs\EvaluateSyncHealthJob;
use App\Domain\Calendar\Outbox\SendCalendarEmail;
use App\Domain\Calendar\SyncHealthService;
use App\Domain\Inventory\Enums\SyncHealth;
use App\Domain\Inventory\Models\Unit;
use App\Domain\Reservations\Outbox\ReservationEmail;
use App\Domain\Tenancy\Models\OutboxMessage;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;

/*
 * 06 "Salute della sincronizzazione" (AC16): ultimo successo < 60 min ACTIVE, 60 min – 3 h DEGRADED
 * (avviso), > 3 h UNHEALTHY (email, unità UNHEALTHY). Le date importate restano bloccate.
 */

function evaluateHealth(array $fx): void
{
    (new EvaluateSyncHealthJob($fx['tenant']))->handle(app(TenantContext::class), app(SyncHealthService::class));
}

function unitHealth(array $fx): SyncHealth
{
    return inTenant($fx['tenant'], fn () => Unit::query()->findOrFail($fx['unit'])->sync_health);
}

it('ACTIVE → DEGRADED → UNHEALTHY con email una sola volta; il successo torna HEALTHY', function () {
    $fx = calendarFixture();
    $fx['http']->responses[$fx['url']] = icsResponse(otaIcs('booking', [['uid' => 'b1@booking.com', 'in' => futureDay(3), 'out' => futureDay(5)]]));
    syncCalendar($fx);

    expect(unitHealth($fx))->toBe(SyncHealth::HEALTHY);

    $fx['http']->responses[$fx['url']] = new FetchFailed(FetchError::TIMEOUT);

    $this->travel(61)->minutes();
    syncCalendar($fx);
    evaluateHealth($fx);
    expect(freshCalendar($fx)->status)->toBe(ExternalCalendarStatus::DEGRADED)
        ->and(unitHealth($fx))->toBe(SyncHealth::WARNING);

    $this->travel(2)->hours();
    syncCalendar($fx);
    evaluateHealth($fx);
    evaluateHealth($fx);

    $emails = inTenant($fx['tenant'], fn () => OutboxMessage::query()->where('type', 'calendar.sync_unhealthy')->get());
    expect(freshCalendar($fx)->status)->toBe(ExternalCalendarStatus::UNHEALTHY)
        ->and(unitHealth($fx))->toBe(SyncHealth::UNHEALTHY)
        ->and($emails)->toHaveCount(1)
        ->and($emails[0]->payload)->toBe(['external_calendar_id' => $fx['calendar']->id])
        // le date restano bloccate
        ->and(calendarEvents($fx)['b1@booking.com']->status->value)->toBe('ACTIVE');

    $fx['http']->responses[$fx['url']] = icsResponse(otaIcs('booking', [['uid' => 'b1@booking.com', 'in' => futureDay(3), 'out' => futureDay(5)]]));
    syncCalendar($fx);

    expect(freshCalendar($fx)->status)->toBe(ExternalCalendarStatus::ACTIVE)
        ->and(unitHealth($fx))->toBe(SyncHealth::HEALTHY);
});

it('soglie: 59 min ACTIVE, 60 min DEGRADED, 180 min DEGRADED, 181 min UNHEALTHY; mai sincronizzato conta dalla creazione', function () {
    $fx = calendarFixture();
    $calendar = freshCalendar($fx);
    $now = now()->toImmutable();

    $at = fn (int $minutes) => SyncHealthService::healthFor(tap(clone $calendar, fn ($c) => $c->last_success_at = $now->subMinutes($minutes)), $now);

    expect($at(59))->toBe(ExternalCalendarStatus::ACTIVE)
        ->and($at(60))->toBe(ExternalCalendarStatus::DEGRADED)
        ->and($at(180))->toBe(ExternalCalendarStatus::DEGRADED)
        ->and($at(181))->toBe(ExternalCalendarStatus::UNHEALTHY)
        ->and(SyncHealthService::healthFor($calendar, $now->addHours(4)))->toBe(ExternalCalendarStatus::UNHEALTHY);
});

it('l\'email di calendario non sincronizzato non contiene dati dell\'ospite', function () {
    $fx = bookableTenant();
    $cal = calendarOn($fx);
    syncWith($cal, otaIcs('booking', [['uid' => 'b1@booking.com', 'in' => '2027-08-01', 'out' => '2027-08-03']]));
    $this->travel(4)->hours();
    evaluateHealth($fx);

    $message = inTenant($fx['tenant'], fn () => OutboxMessage::query()->where('type', 'calendar.sync_unhealthy')->firstOrFail());
    Mail::fake();
    inTenant($fx['tenant'], fn () => app(SendCalendarEmail::class)->handle($message));

    Mail::assertSent(ReservationEmail::class, function ($mail) {
        return $mail->hasTo('gestore@example.test') && str_contains($mail->subjectLine, 'Booking.com')
            && ! str_contains(implode(' ', $mail->lines), 'b1@booking.com');
    });
});

it('il dispatcher (app_admin_ops) accoda un job per tenant con calendari attivi', function () {
    Queue::fake();
    $fx = committedTenantWithUnit(['booking_mode' => 'REQUEST']);
    $db = DB::connection(committedConnection('pgsql_migrator', $fx['tenant']));

    try {
        $db->table('external_calendars')->insert([
            'id' => uuid7(), 'tenant_id' => $fx['tenant'], 'unit_id' => $fx['unit'], 'source' => 'AIRBNB',
            'url_encrypted' => 'x', 'created_at' => now(), 'updated_at' => now(),
        ]);

        (new DispatchSyncHealthJob)->handle(app('db'));

        Queue::assertPushed(EvaluateSyncHealthJob::class, fn (EvaluateSyncHealthJob $job) => $job->tenantId === $fx['tenant']);
    } finally {
        $db->table('external_calendars')->where('tenant_id', $fx['tenant'])->delete();
        deleteCommittedTenant($fx['tenant']);
    }
});
