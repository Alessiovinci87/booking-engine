<?php

namespace App\Domain\Calendar;

use App\Domain\Calendar\Enums\EchoKind;
use App\Domain\Calendar\Enums\IcsFeedStatus;
use App\Domain\Calendar\Export\FeedContents;
use App\Domain\Calendar\Export\FeedItem;
use App\Domain\Calendar\Import\ParsedEvent;
use App\Domain\Calendar\Models\ExternalCalendar;
use App\Domain\Calendar\Models\IcsFeed;
use App\Domain\Inventory\InventoryService;
use App\Domain\Reservations\StayRange;
use Carbon\CarbonImmutable;

/**
 * Classificazione degli echi (06 "Classificazione degli echi"; 5.3): un evento che un'OTA ci rimanda
 * dopo averlo importato dal nostro feed.
 *
 * - UID con il nostro dominio (`@{dominio}`) → CONFIRMED_ECHO, con l'UID originale;
 * - UID estraneo ma intervallo **identico** a un elemento che esportiamo nel feed di quella stessa OTA,
 *   già presente quando l'OTA ha letto il feed per l'ultima volta → SUSPECTED_ECHO;
 * - altrimenti NONE.
 *
 * Il feed "di quella stessa OTA" è quello accoppiato al calendario o, in mancanza, quello dell'unità
 * con destinazione uguale all'origine del calendario (ACTIVE o FROZEN).
 */
class EchoClassifier
{
    public function __construct(private readonly FeedContents $contents) {}

    /**
     * @return array{0: EchoKind, 1: string|null} tipo ed eventuale UID nostro di cui è l'eco confermata
     */
    public function classify(ExternalCalendar $calendar, ParsedEvent $event): array
    {
        if (self::isOurUid($event->uid)) {
            return [EchoKind::CONFIRMED_ECHO, $event->uid];
        }

        return $this->echoedItem($calendar, $event->stay) !== null ? [EchoKind::SUSPECTED_ECHO, null] : [EchoKind::NONE, null];
    }

    /**
     * Elemento del nostro feed per l'OTA del calendario con lo stesso intervallo, già letto dall'OTA.
     */
    public function echoedItem(ExternalCalendar $calendar, StayRange $stay, ?CarbonImmutable $seenAt = null): ?FeedItem
    {
        $lastRead = $this->lastRead($calendar);

        if ($lastRead === null) {
            return null;
        }

        $seenAt ??= CarbonImmutable::now();

        foreach ($this->contents->forDestination($calendar->unit_id, $calendar->source, $calendar->id) as $item) {
            if ($item->stay->equals($stay) && $item->createdAt->lessThanOrEqualTo($lastRead) && $lastRead->lessThanOrEqualTo($seenAt)) {
                return $item;
            }
        }

        return null;
    }

    public static function isOurUid(string $uid): bool
    {
        return str_ends_with(strtolower($uid), '@'.strtolower(InventoryService::icsDomain()));
    }

    private function lastRead(ExternalCalendar $calendar): ?CarbonImmutable
    {
        $feeds = IcsFeed::query()
            ->where('unit_id', $calendar->unit_id)
            ->whereIn('status', [IcsFeedStatus::ACTIVE->value, IcsFeedStatus::FROZEN->value])
            ->whereNotNull('last_fetched_at')
            ->where(fn ($q) => $q->where('paired_external_calendar_id', $calendar->id)
                ->orWhere(fn ($q) => $q->whereNull('paired_external_calendar_id')->where('destination', $calendar->source->value)))
            ->get();

        $max = $feeds->max(fn (IcsFeed $f) => $f->last_fetched_at?->getTimestamp());

        return $max === null ? null : CarbonImmutable::createFromTimestamp((int) $max);
    }
}
