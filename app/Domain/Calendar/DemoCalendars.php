<?php

namespace App\Domain\Calendar;

use App\Domain\Calendar\Enums\ChannelSource;
use App\Domain\Calendar\Enums\EchoKind;
use App\Domain\Calendar\Import\ParsedEvent;
use App\Domain\Calendar\Models\ExternalCalendar;
use App\Domain\Calendar\StateMachines\ExternalEventStateMachine;
use App\Domain\Inventory\Enums\BookingMode;
use App\Domain\Inventory\Models\Unit;
use App\Domain\Reservations\StayRange;
use App\Domain\Tenancy\Audit\AuditLogger;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use LogicException;

/**
 * Calendari importati dimostrativi per il seeder demo (ADR-0013 punto 44), senza chiamate esterne.
 *
 * Il calendario nasce con `demo = true` (escluso da polling, "Sincronizza ora" e valutazione della
 * salute) e con l'ultima sincronizzazione riuscita adesso; gli eventi entrano come se arrivassero da
 * una sincronizzazione, con le stesse macchine a stati, la stessa rilevazione dei conflitti e lo
 * stesso accoppiamento con i feed esportati dell'import vero. Gira nel contesto tenant.
 */
final class DemoCalendars
{
    public function __construct(
        private readonly ExternalEventStateMachine $events,
        private readonly ConflictDetector $detector,
        private readonly IcsFeedLifecycle $feeds,
        private readonly SyncHealthService $health,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  list<array{uid: string, stay: StayRange, summary: string|null}>  $events
     */
    public function create(Unit $unit, ChannelSource $source, array $events): ExternalCalendar
    {
        if ($unit->booking_mode !== BookingMode::REQUEST) {
            throw new LogicException('Un\'unità con calendari esterni è sempre REQUEST (I12).');
        }

        $now = CarbonImmutable::now();
        $calendar = new ExternalCalendar;
        $calendar->id = (string) Str::uuid7();
        $calendar->forceFill([
            'unit_id' => $unit->id,
            'source' => $source,
            'url_encrypted' => 'https://calendari-demo.invalid/'.Str::lower($source->value).'.ics',
            'demo' => true,
            'last_attempt_at' => $now,
            'last_success_at' => $now,
        ])->save();
        $this->audit->record('external_calendar.created', 'external_calendar', $calendar->id, ['unit_id' => $unit->id, 'source' => $source->value, 'demo' => true]);

        foreach ($events as $event) {
            $parsed = new ParsedEvent($event['uid'], $event['stay'], $event['summary'], hash('sha256', $event['uid'].'|'.$event['stay']->toPostgres()));
            $this->detector->detect($calendar, $this->events->create($calendar, $parsed, EchoKind::NONE, null, $now));
        }

        $this->feeds->pairWithCalendar($calendar);
        $this->health->refreshUnit($unit->id);

        return $calendar->refresh();
    }
}
