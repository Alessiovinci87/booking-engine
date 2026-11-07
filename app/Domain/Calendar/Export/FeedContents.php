<?php

namespace App\Domain\Calendar\Export;

use App\Domain\Calendar\Enums\ChannelSource;
use App\Domain\Calendar\Enums\EchoKind;
use App\Domain\Calendar\Enums\ExternalEventStatus;
use App\Domain\Calendar\Models\ExternalCalendarEvent;
use App\Domain\Inventory\Enums\ConflictStatus;
use App\Domain\Inventory\Enums\OccupancyKind;
use App\Domain\Inventory\InventoryService;
use App\Domain\Inventory\Models\InventoryConflict;
use App\Domain\Reservations\StayRange;
use Carbon\CarbonImmutable;

/**
 * Contenuto del feed per una destinazione (06 "Matrice dei flussi", I18): tutte le occupazioni attive
 * dell'unità tranne gli eventi importati dal calendario accoppiato alla destinazione (o, per Booking e
 * Airbnb, da un calendario della stessa OTA) e tutti gli echi.
 *
 * - prenotazioni in ogni stato occupante e blocchi manuali: UID `occ-{id}@{dominio}`;
 * - eventi importati non eco, ACTIVE o PENDING_REMOVAL (le date sono ancora bloccate): `ext-{id}@{dominio}`;
 * - conflitti aperti solo se le loro notti non sono già coperte da altri elementi: `cfl-{id}@{dominio}`;
 * - intervalli identici accorpati per precedenza (prenotazione > blocco > evento importato > conflitto).
 *
 * Solo gli intervalli che finiscono dopo oggi (fuso della struttura): il passato non serve alle OTA.
 */
final class FeedContents
{
    /** Orizzonte del feed: oltre non si vende (07, calendario di 18 mesi). */
    private const HORIZON_DAYS = 760;

    public function __construct(private readonly InventoryService $inventory) {}

    /**
     * @return list<FeedItem> ordinati per check-in
     */
    public function forDestination(string $unitId, ChannelSource $destination, ?string $pairedCalendarId, ?CarbonImmutable $today = null): array
    {
        $today ??= CarbonImmutable::parse(CarbonImmutable::now('Europe/Rome')->toDateString(), 'UTC');
        $window = StayRange::of($today, $today->addDays(self::HORIZON_DAYS));
        $domain = InventoryService::icsDomain();
        $items = [];

        foreach ($this->inventory->occupancyFacts($unitId, $window) as $o) {
            $items[] = new FeedItem(
                $o['kind'] === OccupancyKind::RESERVATION ? FeedItem::RESERVATION : FeedItem::MANUAL_BLOCK,
                $o['id'], $o['ics_uid'], $o['stay'], $o['created_at'], $o['updated_at'], $o['ics_sequence'],
            );
        }

        $events = ExternalCalendarEvent::query()
            ->join('external_calendars as c', fn ($join) => $join->on('c.id', '=', 'external_calendar_events.external_calendar_id')
                ->on('c.tenant_id', '=', 'external_calendar_events.tenant_id'))
            ->where('c.unit_id', $unitId)
            ->whereIn('external_calendar_events.status', [ExternalEventStatus::ACTIVE->value, ExternalEventStatus::PENDING_REMOVAL->value])
            ->where('external_calendar_events.echo_kind', EchoKind::NONE->value)
            ->whereRaw('external_calendar_events.stay && ?::daterange', [$window->toPostgres()])
            ->when($pairedCalendarId !== null, fn ($q) => $q->where('c.id', '<>', $pairedCalendarId))
            ->when($destination !== ChannelSource::OTHER, fn ($q) => $q->where('c.source', '<>', $destination->value))
            ->get(['external_calendar_events.*']);

        foreach ($events as $e) {
            $items[] = new FeedItem(
                FeedItem::EXTERNAL_EVENT, $e->id, 'ext-'.$e->id.'@'.$domain, $e->stay,
                $e->first_seen_at, CarbonImmutable::instance($e->updated_at ?? $e->first_seen_at), $e->ics_sequence,
            );
        }

        $items = self::merge($items);

        $conflicts = InventoryConflict::query()
            ->where('unit_id', $unitId)
            ->whereIn('status', [ConflictStatus::OPEN->value, ConflictStatus::INVESTIGATING->value])
            ->whereRaw('stay && ?::daterange', [$window->toPostgres()])
            ->get();

        foreach ($conflicts as $c) {
            if (! self::covered($c->stay, $items)) {
                $items[] = new FeedItem(
                    FeedItem::CONFLICT, $c->id, 'cfl-'.$c->id.'@'.$domain, $c->stay,
                    $c->detected_at, CarbonImmutable::instance($c->updated_at ?? $c->detected_at),
                );
            }
        }

        $items = self::merge($items);
        usort($items, fn (FeedItem $a, FeedItem $b): int => [$a->stay->checkIn, $a->precedence(), $a->uid] <=> [$b->stay->checkIn, $b->precedence(), $b->uid]);

        return $items;
    }

    /**
     * Accorpamento (06): per ogni intervallo identico resta l'elemento con la precedenza più alta.
     *
     * @param  list<FeedItem>  $items
     * @return list<FeedItem>
     */
    public static function merge(array $items): array
    {
        $byStay = [];

        foreach ($items as $item) {
            $key = $item->stay->toPostgres();
            $current = $byStay[$key] ?? null;

            if ($current === null || [$item->precedence(), $item->uid] < [$current->precedence(), $current->uid]) {
                $byStay[$key] = $item;
            }
        }

        return array_values($byStay);
    }

    /** @param  list<FeedItem>  $items */
    private static function covered(StayRange $stay, array $items): bool
    {
        foreach ($stay->nightDates() as $night) {
            $hit = false;

            foreach ($items as $item) {
                if ($night->greaterThanOrEqualTo($item->stay->checkIn) && $night->lessThan($item->stay->checkOut)) {
                    $hit = true;
                    break;
                }
            }

            if (! $hit) {
                return false;
            }
        }

        return true;
    }
}
