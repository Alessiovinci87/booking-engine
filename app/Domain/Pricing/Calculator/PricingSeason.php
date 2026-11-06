<?php

namespace App\Domain\Pricing\Calculator;

use App\Domain\Reservations\StayRange;
use DateTimeImmutable;

/**
 * Stagione (04): notti di dateRange [inizio, fine), prezzo, priorità, minimo notti facoltativo.
 * createdAt e id servono solo a risolvere le parità (la più recente vince).
 */
final readonly class PricingSeason
{
    public function __construct(
        public string $id,
        public StayRange $dateRange,
        public int $nightlyCents,
        public int $priority,
        public ?int $minNights,
        public DateTimeImmutable $createdAt,
    ) {}
}
