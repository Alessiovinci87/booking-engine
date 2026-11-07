<?php

namespace App\Domain\Calendar;

use App\Domain\Calendar\Enums\EchoKind;
use App\Domain\Calendar\Import\ParsedEvent;
use App\Domain\Calendar\Models\ExternalCalendar;
use App\Domain\Inventory\InventoryService;

/**
 * Classificazione degli echi (06 "Classificazione degli echi"): un evento che un'OTA ci rimanda dopo
 * averlo importato dal nostro feed.
 *
 * - UID con il nostro dominio (`@{dominio}`) → CONFIRMED_ECHO, con l'UID originale;
 * - altrimenti NONE (le echi sospette si aggiungono in 5.3).
 */
class EchoClassifier
{
    /**
     * @return array{0: EchoKind, 1: string|null} tipo ed eventuale UID nostro di cui è l'eco
     */
    public function classify(ExternalCalendar $calendar, ParsedEvent $event): array
    {
        if (self::isOurUid($event->uid)) {
            return [EchoKind::CONFIRMED_ECHO, $event->uid];
        }

        return [EchoKind::NONE, null];
    }

    public static function isOurUid(string $uid): bool
    {
        return str_ends_with(strtolower($uid), '@'.strtolower(InventoryService::icsDomain()));
    }
}
