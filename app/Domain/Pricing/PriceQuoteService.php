<?php

namespace App\Domain\Pricing;

use App\Domain\Inventory\Models\Unit;
use App\Domain\Pricing\Calculator\PriceCalculator;
use App\Domain\Pricing\Calculator\PriceQuote;
use App\Domain\Pricing\Calculator\PriceRequest;
use App\Domain\Pricing\Calculator\PricingRatePlan;
use App\Domain\Pricing\Calculator\PricingSeason;
use App\Domain\Pricing\Calculator\PricingUnit;
use App\Domain\Pricing\Models\SeasonRule;
use DateTimeImmutable;

/**
 * Prepara i DTO del PriceCalculator dai dati salvati (unità, piano attivo, stagioni che toccano il
 * soggiorno) e restituisce il calcolo. È l'unico punto che legge il database per il prezzo: stesso
 * calcolo per l'anteprima del pannello e per la pagina ospite (07).
 */
final class PriceQuoteService
{
    public function __construct(
        private readonly PriceCalculator $calculator,
        private readonly RatePlanService $plans,
    ) {}

    public function quote(Unit $unit, PriceRequest $request): PriceQuote
    {
        $plan = $this->plans->activePlan($unit);

        $seasons = SeasonRule::query()
            ->where('rate_plan_id', $plan->id)
            ->whereRaw('date_range && ?::daterange', [$request->stay->toPostgres()])
            ->get()
            ->map(fn (SeasonRule $s): PricingSeason => new PricingSeason(
                $s->id,
                $s->date_range,
                $s->nightly_cents,
                $s->priority,
                $s->min_nights,
                DateTimeImmutable::createFromInterface($s->created_at ?? new DateTimeImmutable('@0')),
            ))
            ->values()
            ->all();

        return $this->calculator->calculate(
            new PricingUnit(
                $unit->base_occupancy,
                $unit->max_occupancy,
                $unit->cleaning_fee_cents,
                $unit->extra_guest_fee_cents,
                $unit->min_nights,
                $unit->payment_mode,
            ),
            new PricingRatePlan($plan->base_nightly_cents, $plan->currency, $seasons),
            $request,
        );
    }
}
