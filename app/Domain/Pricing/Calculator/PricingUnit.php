<?php

namespace App\Domain\Pricing\Calculator;

use App\Domain\Inventory\Enums\PaymentMode;

/** Dati dell'unità che servono al calcolo (04, "Input"). Importi in centesimi interi. */
final readonly class PricingUnit
{
    public function __construct(
        public int $baseOccupancy,
        public int $maxOccupancy,
        public int $cleaningFeeCents,
        public int $extraGuestFeeCents,
        public int $minNights,
        public PaymentMode $paymentMode,
    ) {}
}
