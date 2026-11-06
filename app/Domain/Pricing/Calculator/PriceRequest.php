<?php

namespace App\Domain\Pricing\Calculator;

use App\Domain\Reservations\StayRange;

/**
 * Richiesta di prezzo (04). I bambini contano come ospiti nella 1.0. Lo sconto arriva solo da
 * preventivo o prenotazione manuale; onlinePayment attiva il minimo Stripe di 50 centesimi.
 */
final readonly class PriceRequest
{
    public function __construct(
        public StayRange $stay,
        public int $adults,
        public int $children = 0,
        public int $discountCents = 0,
        public bool $onlinePayment = true,
    ) {}
}
