<?php

namespace App\Domain\Calendar\StateMachines;

use App\Domain\Calendar\Enums\ExternalCalendarStatus as S;
use App\Domain\Calendar\Models\ExternalCalendar;
use App\Domain\Reservations\Exceptions\IllegalTransition;
use App\Domain\Tenancy\Audit\AuditLogger;

/**
 * Stato di un calendario importato (06 "Salute della sincronizzazione"): ACTIVE, DEGRADED e
 * UNHEALTHY dipendono dall'ultimo successo; DISABLED solo per scelta del gestore.
 */
final class ExternalCalendarStateMachine
{
    public function __construct(private readonly AuditLogger $audit) {}

    /** Stato di salute calcolato (SyncHealth); un calendario DISABLED non cambia. */
    public function setHealth(ExternalCalendar $calendar, S $health): bool
    {
        if ($health === S::DISABLED) {
            throw IllegalTransition::of('external_calendar', $calendar->status->value, 'health:'.$health->value);
        }

        if ($calendar->status === S::DISABLED || $calendar->status === $health) {
            return false;
        }

        $this->move($calendar, $health);

        return true;
    }

    public function disable(ExternalCalendar $calendar): void
    {
        if ($calendar->status === S::DISABLED) {
            throw IllegalTransition::of('external_calendar', $calendar->status->value, 'disable');
        }

        $calendar->next_poll_at = null;
        $this->move($calendar, S::DISABLED);
    }

    public function enable(ExternalCalendar $calendar): void
    {
        if ($calendar->status !== S::DISABLED) {
            throw IllegalTransition::of('external_calendar', $calendar->status->value, 'enable');
        }

        $calendar->next_poll_at = now()->toImmutable();
        $this->move($calendar, S::ACTIVE);
    }

    private function move(ExternalCalendar $calendar, S $to): void
    {
        $from = $calendar->status;
        $calendar->status = $to;
        $calendar->save();

        $this->audit->record('external_calendar.status_changed', 'external_calendar', $calendar->id, [
            'from' => $from->value, 'to' => $to->value,
        ]);
    }
}
