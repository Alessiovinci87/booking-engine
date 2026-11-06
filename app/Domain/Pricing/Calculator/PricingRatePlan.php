<?php

namespace App\Domain\Pricing\Calculator;

/** Piano tariffario attivo con le sue stagioni (04). */
final readonly class PricingRatePlan
{
    /**
     * @param  list<PricingSeason>  $seasons
     */
    public function __construct(
        public int $baseNightlyCents,
        public string $currency,
        public array $seasons,
    ) {}
}
