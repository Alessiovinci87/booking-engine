<?php

namespace App\Domain\Calendar;

use App\Domain\Calendar\Enums\EchoKind;
use App\Domain\Calendar\Models\ExternalCalendar;
use App\Domain\Calendar\Models\ExternalCalendarEvent;
use App\Domain\Calendar\StateMachines\ExternalEventStateMachine;
use App\Domain\Inventory\Enums\ConflictResolution;
use App\Domain\Inventory\Enums\ConflictStatus;
use App\Domain\Inventory\Models\InventoryConflict;
use App\Domain\Inventory\StateMachines\ConflictStateMachine;
use App\Domain\Reservations\Exceptions\IllegalTransition;
use Illuminate\Support\Facades\DB;

/**
 * Gestione dei conflitti (06 "Conflitti", 03 sezione 5; 5.3): azioni del gestore dal pannello,
 * risoluzione automatica quando l'evento esterno viene rimosso con conferma, riclassificazione di
 * un'eco sospetta come prenotazione vera.
 *
 * La chiusura di un conflitto toglie solo il blocco del conflitto (I13): le date restano non vendibili
 * finché le occupano una prenotazione, un blocco o un evento esterno.
 */
class ConflictService
{
    public function __construct(
        private readonly ConflictStateMachine $machine,
        private readonly ConflictDetector $detector,
        private readonly ExternalEventStateMachine $events,
    ) {}

    public function investigate(InventoryConflict $conflict, ?string $userId): InventoryConflict
    {
        return DB::transaction(fn () => $this->machine->investigate($this->lock($conflict), $userId));
    }

    public function resolve(InventoryConflict $conflict, ConflictResolution $resolution, ?string $notes, ?string $userId): InventoryConflict
    {
        return DB::transaction(fn () => $this->machine->resolve($this->lock($conflict), $resolution, $notes, $userId));
    }

    public function ignore(InventoryConflict $conflict, string $notes, ?string $userId): InventoryConflict
    {
        return DB::transaction(fn () => $this->machine->ignore($this->lock($conflict), $notes, $userId));
    }

    /** 06 "Rimozione" punto 4: i conflitti aperti dell'evento rimosso si risolvono con nota automatica. */
    public function eventRemoved(ExternalCalendarEvent $event): int
    {
        $conflicts = InventoryConflict::query()
            ->where('status', ConflictStatus::OPEN->value)
            ->where(fn ($q) => $q->where('external_event_id', $event->id)->orWhere('other_external_event_id', $event->id))
            ->lockForUpdate()
            ->get();

        $resolved = 0;

        foreach ($conflicts as $conflict) {
            $resolved += $this->machine->resolveAutomatically($conflict) ? 1 : 0;
        }

        return $resolved;
    }

    /**
     * "Non è un'eco, è una prenotazione vera" (06): l'eco sospetta torna NONE, viene riesportata e
     * partecipa ai conflitti.
     *
     * @return list<InventoryConflict> conflitti aperti dalla riclassificazione
     */
    public function notAnEcho(ExternalCalendarEvent $event): array
    {
        if ($event->echo_kind !== EchoKind::SUSPECTED_ECHO) {
            throw IllegalTransition::of('external_event_echo', $event->echo_kind->value, 'not_an_echo');
        }

        return DB::transaction(function () use ($event): array {
            $calendar = ExternalCalendar::query()->lockForUpdate()->findOrFail($event->external_calendar_id);
            $this->events->classify($event, EchoKind::NONE);

            return $this->detector->detect($calendar, $event);
        });
    }

    private function lock(InventoryConflict $conflict): InventoryConflict
    {
        return InventoryConflict::query()->lockForUpdate()->findOrFail($conflict->id);
    }
}
