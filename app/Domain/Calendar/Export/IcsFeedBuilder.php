<?php

namespace App\Domain\Calendar\Export;

use App\Domain\Calendar\Enums\IcsFeedStatus;
use App\Domain\Calendar\Models\IcsFeed;
use App\Domain\Inventory\Models\Unit;
use Carbon\CarbonImmutable;
use LogicException;
use Sabre\VObject\Component\VCalendar;

/**
 * Documento iCal di un feed esportato (06 "Export", 5.4), costruito con sabre/vobject:
 *
 * - contenuto per destinazione da FeedContents (matrice dei flussi, I18; accorpamento; UID stabili
 *   `occ-`, `ext-`, `cfl-`);
 * - eventi a giornata intera `DTSTART;VALUE=DATE` / `DTEND;VALUE=DATE`, `SUMMARY:Non disponibile`,
 *   nessun dato dell'ospite, `SEQUENCE` dalla colonna ics_sequence della fonte, `DTSTAMP` dall'ultima
 *   modifica della fonte (documento stabile → ETag stabile);
 * - feed FROZEN: solo gli elementi che esistevano al congelamento, con le modifiche e cancellazioni
 *   successive; se non resta nulla il feed non si serve vuoto (I19): FeedUnavailable.
 *
 * Il feed RETIRED non si costruisce: risponde 410 (IcsFeedLifecycle).
 */
final class IcsFeedBuilder
{
    public const SUMMARY = 'Non disponibile';

    public const PRODID = '-//BookingEngine//Calendar Export 1.0//IT';

    public function __construct(private readonly FeedContents $contents) {}

    /**
     * @throws FeedUnavailable feed FROZEN senza più elementi
     */
    public function build(IcsFeed $feed): FeedDocument
    {
        if ($feed->status === IcsFeedStatus::RETIRED) {
            throw new LogicException('Un feed RETIRED non si costruisce.');
        }

        $timezone = Unit::query()->with('property')->findOrFail($feed->unit_id)->property->timezone;
        $today = CarbonImmutable::parse(CarbonImmutable::now($timezone)->toDateString(), 'UTC');
        $items = $this->contents->forDestination($feed->unit_id, $feed->destination, $feed->paired_external_calendar_id, $today);

        if ($feed->status === IcsFeedStatus::FROZEN) {
            $frozenAt = $feed->frozen_at ?? $feed->updated_at ?? CarbonImmutable::now();
            $items = array_values(array_filter($items, fn (FeedItem $i): bool => $i->createdAt->lessThanOrEqualTo($frozenAt)));

            if ($items === []) {
                throw new FeedUnavailable;
            }
        }

        return new FeedDocument(self::serialize($items), count($items));
    }

    /**
     * @param  list<FeedItem>  $items
     */
    public static function serialize(array $items): string
    {
        $calendar = new VCalendar([
            'PRODID' => self::PRODID,
            'CALSCALE' => 'GREGORIAN',
            'METHOD' => 'PUBLISH',
        ]);

        foreach ($items as $item) {
            $event = $calendar->createComponent('VEVENT', [
                'UID' => $item->uid,
                'DTSTAMP' => $item->updatedAt->setTimezone('UTC'),
                'SUMMARY' => self::SUMMARY,
                'SEQUENCE' => $item->sequence,
                'TRANSP' => 'OPAQUE',
            ]);
            $event->add('DTSTART', $item->stay->checkIn->format('Ymd'), ['VALUE' => 'DATE']);
            $event->add('DTEND', $item->stay->checkOut->format('Ymd'), ['VALUE' => 'DATE']);
            $calendar->add($event);
        }

        return $calendar->serialize();
    }
}
