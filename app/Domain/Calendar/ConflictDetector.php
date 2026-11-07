<?php

namespace App\Domain\Calendar;

use App\Domain\Calendar\Enums\EchoKind;
use App\Domain\Calendar\Enums\ExternalEventStatus;
use App\Domain\Calendar\Models\ExternalCalendar;
use App\Domain\Calendar\Models\ExternalCalendarEvent;
use App\Domain\Inventory\Enums\ConflictStatus;
use App\Domain\Inventory\InventoryService;
use App\Domain\Inventory\Models\InventoryConflict;
use App\Domain\Inventory\StateMachines\ConflictStateMachine;
use App\Domain\Reservations\StayRange;

/**
 * Rilevamento dei conflitti (06 "Conflitti"; 5.3). Dopo ogni evento importato nuovo, modificato o
 * ricomparso, non eco confermata, si cercano sovrapposizioni con:
 *
 * - occupazioni attive dell'unità (prenotazioni in ogni stato occupante, blocchi manuali);
 * - eventi ACTIVE non eco di altri calendari dell'unità.
 *
 * Per ogni sovrapposizione nuova: InventoryConflict OPEN sulle notti in comune, notifica al gestore.
 * Nessuna cancellazione automatica. Un'eco sospetta genera un conflitto (`suspected_echo`) solo con
 * occupazioni diverse da quella di cui sembra l'eco. Una coppia con un conflitto già aperto non ne
 * genera un secondo.
 */
class ConflictDetector
{
    public function __construct(
        private readonly InventoryService $inventory,
        private readonly EchoClassifier $echoes,
        private readonly ConflictStateMachine $conflicts,
    ) {}

    /**
     * @return list<InventoryConflict> conflitti aperti ora
     */
    public function detect(ExternalCalendar $calendar, ExternalCalendarEvent $event): array
    {
        if ($event->echo_kind === EchoKind::CONFIRMED_ECHO || $event->status === ExternalEventStatus::REMOVED) {
            return [];
        }

        $suspected = $event->echo_kind === EchoKind::SUSPECTED_ECHO;
        $echoedId = $suspected ? $this->echoes->echoedItem($calendar, $event->stay)?->sourceId : null;
        $opened = [];

        foreach ($this->inventory->occupancyFacts($calendar->unit_id, $event->stay) as $occupancy) {
            if ($occupancy['id'] === $echoedId || $this->alreadyOpen($event->id, occupancyId: $occupancy['id'])) {
                continue;
            }

            $opened[] = $this->conflicts->open($calendar->unit_id, self::overlap($event->stay, $occupancy['stay']), $occupancy['id'], $event->id, null, $suspected);
        }

        $others = ExternalCalendarEvent::query()
            ->join('external_calendars as c', fn ($join) => $join->on('c.id', '=', 'external_calendar_events.external_calendar_id')
                ->on('c.tenant_id', '=', 'external_calendar_events.tenant_id'))
            ->where('c.unit_id', $calendar->unit_id)
            ->where('c.id', '<>', $calendar->id)
            ->where('external_calendar_events.status', ExternalEventStatus::ACTIVE->value)
            ->where('external_calendar_events.echo_kind', EchoKind::NONE->value)
            ->whereRaw('external_calendar_events.stay && ?::daterange', [$event->stay->toPostgres()])
            ->get(['external_calendar_events.*']);

        foreach ($others as $other) {
            if ($other->id === $echoedId || $this->alreadyOpen($event->id, otherEventId: $other->id)) {
                continue;
            }

            $opened[] = $this->conflicts->open($calendar->unit_id, self::overlap($event->stay, $other->stay), null, $event->id, $other->id, $suspected);
        }

        return $opened;
    }

    public static function overlap(StayRange $a, StayRange $b): StayRange
    {
        return StayRange::of($a->checkIn->max($b->checkIn), $a->checkOut->min($b->checkOut));
    }

    private function alreadyOpen(string $eventId, ?string $occupancyId = null, ?string $otherEventId = null): bool
    {
        $open = [ConflictStatus::OPEN->value, ConflictStatus::INVESTIGATING->value];

        if ($occupancyId !== null) {
            return InventoryConflict::query()->whereIn('status', $open)
                ->where('external_event_id', $eventId)->where('occupancy_id', $occupancyId)->exists();
        }

        return InventoryConflict::query()->whereIn('status', $open)
            ->where(fn ($q) => $q->where(fn ($q) => $q->where('external_event_id', $eventId)->where('other_external_event_id', $otherEventId))
                ->orWhere(fn ($q) => $q->where('external_event_id', $otherEventId)->where('other_external_event_id', $eventId)))
            ->exists();
    }
}
