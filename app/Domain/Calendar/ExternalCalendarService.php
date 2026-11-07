<?php

namespace App\Domain\Calendar;

use App\Domain\Calendar\Enums\ChannelSource;
use App\Domain\Calendar\Enums\ExternalCalendarStatus;
use App\Domain\Calendar\Enums\ExternalEventStatus;
use App\Domain\Calendar\Enums\SyncRunOutcome;
use App\Domain\Calendar\Http\FetchFailed;
use App\Domain\Calendar\Models\ExternalCalendar;
use App\Domain\Calendar\Models\ExternalCalendarEvent;
use App\Domain\Calendar\Models\SyncRun;
use App\Domain\Calendar\StateMachines\ExternalCalendarStateMachine;
use App\Domain\Calendar\StateMachines\ExternalEventStateMachine;
use App\Domain\Inventory\Enums\BookingMode;
use App\Domain\Inventory\Models\Unit;
use App\Domain\Inventory\UnitService;
use App\Domain\Tenancy\Audit\AuditLogger;
use App\Domain\Tenancy\Exceptions\BusinessRuleViolation;
use App\Domain\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

/**
 * Calendari importati dal pannello (07 "Calendario", 06 "Configurazione e sicurezza"; 5.6):
 *
 * - creazione: URL controllato da SafeHttpFetcher (422 INVALID_ICAL_URL), prima sincronizzazione
 *   obbligatoria (se fallisce il calendario non viene salvato); su un'unità INSTANT serve la conferma
 *   esplicita del passaggio a REQUEST (I12), altrimenti 409 UNIT_IS_INSTANT;
 * - cambio di URL: gli eventi del vecchio URL restano finché il nuovo non li conferma o il gestore li
 *   rimuove (06 "Rimozione");
 * - disattivazione e riattivazione; rimozione del calendario: eventi rimossi su richiesta esplicita
 *   del gestore (le date si liberano), calendario disattivato e conservato per lo storico;
 * - "sincronizza ora": al massimo una volta ogni 2 minuti per calendario.
 *
 * Le chiamate HTTP avvengono fuori dalle transazioni: i metodi aprono i propri passi con TenantContext.
 */
class ExternalCalendarService
{
    public const SYNC_NOW_SECONDS = 120;

    public function __construct(
        private readonly TenantContext $tenants,
        private readonly SafeHttpFetcher $fetcher,
        private readonly IcalImportService $import,
        private readonly ExternalCalendarStateMachine $calendars,
        private readonly ExternalEventStateMachine $events,
        private readonly ConflictService $conflicts,
        private readonly IcsFeedLifecycle $feeds,
        private readonly UnitService $units,
        private readonly SyncHealthService $health,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @return array{calendar: ExternalCalendar, run: SyncRun}
     */
    public function create(string $tenantId, string $unitId, ChannelSource $source, string $url, bool $switchToRequest): array
    {
        $this->assertUrl($url);

        $calendar = $this->tenants->run($tenantId, function () use ($unitId, $source, $url, $switchToRequest): ExternalCalendar {
            $unit = Unit::query()->lockForUpdate()->findOrFail($unitId);

            if ($unit->booking_mode === BookingMode::INSTANT) {
                if (! $switchToRequest) {
                    throw BusinessRuleViolation::make(409, 'UNIT_IS_INSTANT', ['unit_id' => $unit->id]);
                }

                $this->units->update($unit, ['booking_mode' => BookingMode::REQUEST]);
            }

            $calendar = new ExternalCalendar;
            $calendar->id = (string) Str::uuid7();
            $calendar->forceFill(['unit_id' => $unit->id, 'source' => $source, 'url_encrypted' => $url])->save();
            $this->audit->record('external_calendar.created', 'external_calendar', $calendar->id, ['unit_id' => $unit->id, 'source' => $source->value]);

            return $calendar;
        });

        $run = $this->import->sync($tenantId, $calendar->id);

        if ($run === null || $run->outcome === SyncRunOutcome::ERROR) {
            $this->tenants->run($tenantId, function () use ($calendar): void {
                SyncRun::query()->where('external_calendar_id', $calendar->id)->delete();
                ExternalCalendar::query()->whereKey($calendar->id)->delete();
                $this->audit->record('external_calendar.first_sync_failed', 'external_calendar', $calendar->id);
            });

            throw BusinessRuleViolation::make(422, 'INVALID_ICAL_URL', ['reason' => $run?->error_code]);
        }

        return $this->tenants->run($tenantId, function () use ($calendar, $run): array {
            $fresh = ExternalCalendar::query()->findOrFail($calendar->id);
            $this->feeds->pairWithCalendar($fresh);
            $this->health->refreshUnit($fresh->unit_id);

            return ['calendar' => $fresh, 'run' => $run];
        });
    }

    /** Cambio di URL (06): ETag azzerato, eventi del vecchio URL conservati, nuova sincronizzazione subito. */
    public function changeUrl(string $tenantId, string $calendarId, string $url): ?SyncRun
    {
        $this->assertUrl($url);

        $this->tenants->run($tenantId, function () use ($calendarId, $url): void {
            $calendar = ExternalCalendar::query()->lockForUpdate()->findOrFail($calendarId);
            $calendar->url_encrypted = $url;
            $calendar->url_changed_at = CarbonImmutable::now();
            $calendar->etag = null;
            $calendar->last_modified = null;
            $calendar->next_poll_at = CarbonImmutable::now();
            $calendar->save();
            $this->audit->record('external_calendar.url_changed', 'external_calendar', $calendar->id);
        });

        return $this->import->sync($tenantId, $calendarId);
    }

    public function disable(ExternalCalendar $calendar): ExternalCalendar
    {
        $this->calendars->disable($calendar);
        $this->health->refreshUnit($calendar->unit_id);

        return $calendar;
    }

    public function enable(ExternalCalendar $calendar): ExternalCalendar
    {
        $unit = Unit::query()->findOrFail($calendar->unit_id);

        if ($unit->booking_mode === BookingMode::INSTANT) {
            throw BusinessRuleViolation::make(409, 'UNIT_IS_INSTANT', ['unit_id' => $unit->id]);
        }

        $this->calendars->enable($calendar);
        $this->health->refreshUnit($calendar->unit_id);

        return $calendar;
    }

    /** Rimozione del calendario chiesta dal gestore: eventi rimossi (date libere), calendario disattivato. */
    public function remove(ExternalCalendar $calendar): int
    {
        if ($calendar->status !== ExternalCalendarStatus::DISABLED) {
            $this->calendars->disable($calendar);
        }

        $events = ExternalCalendarEvent::query()->where('external_calendar_id', $calendar->id)
            ->where('status', '<>', ExternalEventStatus::REMOVED->value)->lockForUpdate()->get();

        foreach ($events as $event) {
            $this->events->removeByManager($event);
            $this->conflicts->eventRemoved($event);
        }

        $this->audit->record('external_calendar.removed', 'external_calendar', $calendar->id, ['events_removed' => $events->count()]);
        $this->health->refreshUnit($calendar->unit_id);

        return $events->count();
    }

    /** Rimozione manuale di un singolo evento (cambio di URL, 06). */
    public function removeEvent(ExternalCalendarEvent $event): void
    {
        $this->events->removeByManager($event);
        $this->conflicts->eventRemoved($event);
    }

    /** "Sincronizza ora" (07): al massimo una volta ogni 2 minuti per calendario. */
    public function syncNow(string $tenantId, string $calendarId): ?SyncRun
    {
        $key = 'calendar-sync-now:'.$calendarId;

        if (RateLimiter::tooManyAttempts($key, 1)) {
            throw BusinessRuleViolation::make(429, 'RATE_LIMITED', ['retry_after' => RateLimiter::availableIn($key)]);
        }

        RateLimiter::hit($key, self::SYNC_NOW_SECONDS);

        return Cache::lock('calendar-sync:'.$calendarId, Jobs\SyncExternalCalendarJob::LOCK_SECONDS)
            ->get(fn () => $this->import->sync($tenantId, $calendarId));
    }

    private function assertUrl(string $url): void
    {
        try {
            $this->fetcher->assertAllowed($url);
        } catch (FetchFailed $e) {
            throw BusinessRuleViolation::make(422, 'INVALID_ICAL_URL', ['reason' => $e->error->value]);
        }
    }
}
