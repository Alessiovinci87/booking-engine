<?php

namespace App\Domain\Calendar;

use App\Domain\Calendar\Enums\ExternalCalendarStatus;
use App\Domain\Calendar\Models\ExternalCalendar;
use App\Domain\Calendar\StateMachines\ExternalCalendarStateMachine;
use App\Domain\Inventory\Enums\SyncHealth;
use App\Domain\Inventory\Models\Unit;
use App\Domain\Inventory\UnitService;
use App\Domain\Tenancy\Outbox\Outbox;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;

/**
 * Salute della sincronizzazione (06 "Salute della sincronizzazione"; 5.6):
 *
 * | Ultimo successo | Stato del calendario | Effetto |
 * |---|---|---|
 * | < 60 min | ACTIVE | — |
 * | 60 min – 3 h | DEGRADED | avviso nel pannello |
 * | > 3 h | UNHEALTHY | email al gestore; unità `sync_health = UNHEALTHY` |
 *
 * Un calendario mai sincronizzato conta dalla creazione. L'unità prende lo stato peggiore dei suoi
 * calendari non disattivati: UNHEALTHY, WARNING (almeno un DEGRADED), HEALTHY, NONE se non ne ha.
 * Con UNHEALTHY le richieste restano accettabili con avviso (06); il gestore può sospendere le vendite.
 */
class SyncHealthService
{
    public const DEGRADED_AFTER_MINUTES = 60;

    public const UNHEALTHY_AFTER_MINUTES = 180;

    public function __construct(
        private readonly ExternalCalendarStateMachine $calendars,
        private readonly UnitService $units,
        private readonly Outbox $outbox,
    ) {}

    public static function healthFor(ExternalCalendar $calendar, CarbonImmutable $now): ExternalCalendarStatus
    {
        $reference = $calendar->last_success_at ?? CarbonImmutable::instance($calendar->created_at ?? $now);
        $minutes = (int) floor($reference->diffInSeconds($now, true) / 60);

        return match (true) {
            $minutes > self::UNHEALTHY_AFTER_MINUTES => ExternalCalendarStatus::UNHEALTHY,
            $minutes >= self::DEGRADED_AFTER_MINUTES => ExternalCalendarStatus::DEGRADED,
            default => ExternalCalendarStatus::ACTIVE,
        };
    }

    /** Valuta tutti i calendari del tenant corrente (job ogni 5 minuti). */
    public function evaluateTenant(CarbonImmutable $now): void
    {
        $calendars = ExternalCalendar::query()->where('status', '<>', ExternalCalendarStatus::DISABLED->value)->lockForUpdate()->get();

        foreach ($calendars as $calendar) {
            $this->evaluate($calendar, $now);
        }

        $units = Unit::query()->whereIn('id', ExternalCalendar::query()->select('unit_id'))->orWhere('sync_health', '<>', SyncHealth::NONE->value)->pluck('id');

        foreach ($units as $unitId) {
            $this->refreshUnit((string) $unitId);
        }
    }

    public function evaluate(ExternalCalendar $calendar, CarbonImmutable $now): void
    {
        $health = self::healthFor($calendar, $now);

        if ($this->calendars->setHealth($calendar, $health) && $health === ExternalCalendarStatus::UNHEALTHY) {
            $this->outbox->enqueue(
                'calendar.sync_unhealthy',
                'calendar.sync_unhealthy:'.$calendar->id.':'.($calendar->last_success_at?->getTimestamp() ?? 'never'),
                ['external_calendar_id' => $calendar->id],
            );
            Log::warning('calendar.sync_unhealthy', ['tenant_id' => $calendar->tenant_id, 'external_calendar_id' => $calendar->id]);
        }
    }

    /** Stato dell'unità dal peggiore dei suoi calendari attivi. */
    public function refreshUnit(string $unitId): SyncHealth
    {
        $statuses = ExternalCalendar::query()->where('unit_id', $unitId)
            ->where('status', '<>', ExternalCalendarStatus::DISABLED->value)
            ->pluck('status')->map(fn ($s) => $s instanceof ExternalCalendarStatus ? $s : ExternalCalendarStatus::from((string) $s));

        $health = match (true) {
            $statuses->isEmpty() => SyncHealth::NONE,
            $statuses->contains(ExternalCalendarStatus::UNHEALTHY) => SyncHealth::UNHEALTHY,
            $statuses->contains(ExternalCalendarStatus::DEGRADED) => SyncHealth::WARNING,
            default => SyncHealth::HEALTHY,
        };

        $unit = Unit::query()->findOrFail($unitId);
        $this->units->setSyncHealth($unit, $health);

        return $health;
    }
}
