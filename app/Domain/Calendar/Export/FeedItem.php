<?php

namespace App\Domain\Calendar\Export;

use App\Domain\Reservations\StayRange;
use Carbon\CarbonImmutable;

/**
 * Elemento di un feed iCal esportato (06 "Un'occupazione logica, un UID"): sempre "Non disponibile",
 * senza dati dell'ospite.
 */
final readonly class FeedItem
{
    public const RESERVATION = 'RESERVATION';

    public const MANUAL_BLOCK = 'MANUAL_BLOCK';

    public const EXTERNAL_EVENT = 'EXTERNAL_EVENT';

    public const CONFLICT = 'CONFLICT';

    /** Precedenza nell'accorpamento di intervalli identici: più basso vince (06). */
    public const PRECEDENCE = [self::RESERVATION => 1, self::MANUAL_BLOCK => 2, self::EXTERNAL_EVENT => 3, self::CONFLICT => 4];

    public function __construct(
        public string $type,
        public string $sourceId,
        public string $uid,
        public StayRange $stay,
        public CarbonImmutable $createdAt,
        public CarbonImmutable $updatedAt,
    ) {}

    public function precedence(): int
    {
        return self::PRECEDENCE[$this->type];
    }
}
