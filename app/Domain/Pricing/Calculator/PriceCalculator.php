<?php

namespace App\Domain\Pricing\Calculator;

use App\Domain\Inventory\Enums\PaymentMode;
use App\Domain\Pricing\Enums\PriceLineKind;
use InvalidArgumentException;

/**
 * Calcolo del prezzo (04): funzione pura. Nessun accesso a database, rete, orologio o configurazione:
 * riceve tariffe e richiesta come DTO e restituisce righe, totale, caparra e saldo. Solo interi in
 * centesimi in ogni passaggio. Arch/PriceCalculatorPurityTest verifica le dipendenze.
 *
 * Ordine dei controlli: ospiti (OCCUPANCY_EXCEEDED), minimo notti (MIN_NIGHTS_NOT_MET), sconto,
 * minimo pagabile online (AMOUNT_BELOW_MINIMUM).
 */
final class PriceCalculator
{
    public function calculate(PricingUnit $unit, PricingRatePlan $plan, PriceRequest $request): PriceQuote
    {
        if ($request->adults < 1 || $request->children < 0 || $request->discountCents < 0) {
            throw new InvalidArgumentException('Ospiti o sconto non validi.');
        }

        $guests = $request->adults + $request->children;

        if ($guests > $unit->maxOccupancy) {
            throw PricingError::occupancyExceeded($unit->maxOccupancy, $guests);
        }

        $nightDates = $request->stay->nightDates();
        $nights = count($nightDates);
        $winners = [];
        $requiredMinNights = $unit->minNights;

        foreach ($nightDates as $date) {
            $day = $date->toDateString();
            $winner = $this->winningSeason($plan->seasons, $day);
            $winners[$day] = $winner;

            if ($winner?->minNights !== null) {
                $requiredMinNights = max($requiredMinNights, $winner->minNights);
            }
        }

        if ($nights < $requiredMinNights) {
            throw PricingError::minNightsNotMet($requiredMinNights, $nights);
        }

        $extra = max(0, $guests - $unit->baseOccupancy);
        $lines = [];
        $subtotal = 0;

        foreach ($winners as $day => $winner) {
            $price = $winner === null ? $plan->baseNightlyCents : $winner->nightlyCents;
            $lines[] = new PriceLine(PriceLineKind::NIGHT, $day, 1, $price, $price, $winner?->id);
            $subtotal += $price;

            if ($extra > 0) {
                $amount = $extra * $unit->extraGuestFeeCents;
                $lines[] = new PriceLine(PriceLineKind::EXTRA_GUEST, $day, $extra, $unit->extraGuestFeeCents, $amount);
                $subtotal += $amount;
            }
        }

        if ($unit->cleaningFeeCents > 0) {
            $lines[] = new PriceLine(PriceLineKind::CLEANING, null, 1, $unit->cleaningFeeCents, $unit->cleaningFeeCents);
            $subtotal += $unit->cleaningFeeCents;
        }

        if ($request->discountCents > $subtotal) {
            throw PricingError::discountOutOfRange($request->discountCents, $subtotal);
        }

        if ($request->discountCents > 0) {
            $lines[] = new PriceLine(PriceLineKind::DISCOUNT, null, 1, -$request->discountCents, -$request->discountCents);
        }

        $total = $subtotal - $request->discountCents;

        if ($request->onlinePayment && $total < PricingError::STRIPE_MINIMUM_CENTS) {
            throw PricingError::amountBelowMinimum($total);
        }

        [$deposit, $balance] = self::split($total, $unit->paymentMode);

        return new PriceQuote(
            lines: $lines,
            nights: $nights,
            guests: $guests,
            requiredMinNights: $requiredMinNights,
            subtotalCents: $subtotal,
            discountCents: $request->discountCents,
            totalCents: $total,
            depositCents: $deposit,
            balanceCents: $balance,
            paymentMode: $unit->paymentMode,
            currency: $plan->currency,
        );
    }

    /**
     * Caparra e saldo (04): FULL tutto subito; DEPOSIT_30 caparra = ceil(totale × 30 / 100) in interi,
     * l'arrotondamento cade sulla caparra e deposit + balance = totale.
     *
     * @return array{0: int, 1: int}
     */
    public static function split(int $total, PaymentMode $mode): array
    {
        if ($mode === PaymentMode::FULL) {
            return [$total, 0];
        }

        $deposit = intdiv($total * 30 + 99, 100);

        return [$deposit, $total - $deposit];
    }

    /**
     * Simulazione per canale, solo informativa (04): prezzo OTA per incassare lo stesso netto,
     * ceil(prezzo × 10000 / (10000 − commissione_bps)) in interi.
     */
    public static function suggestedOtaPrice(int $directCents, int $commissionBps): int
    {
        if ($commissionBps < 0 || $commissionBps > 9999 || $directCents < 0) {
            throw new InvalidArgumentException('Commissione o prezzo non validi.');
        }

        $divisor = 10000 - $commissionBps;

        return intdiv($directCents * 10000 + $divisor - 1, $divisor);
    }

    /**
     * Stagione vincente per la notte (04): priorità più alta; a parità, intervallo più corto; a parità,
     * la più recente (created_at, poi id UUID v7 per un ordine sempre deterministico).
     *
     * @param  list<PricingSeason>  $seasons
     */
    private function winningSeason(array $seasons, string $day): ?PricingSeason
    {
        $winner = null;

        foreach ($seasons as $season) {
            if ($day < $season->dateRange->checkIn->toDateString() || $day >= $season->dateRange->checkOut->toDateString()) {
                continue;
            }

            if ($winner === null || self::beats($season, $winner)) {
                $winner = $season;
            }
        }

        return $winner;
    }

    private static function beats(PricingSeason $candidate, PricingSeason $current): bool
    {
        if ($candidate->priority !== $current->priority) {
            return $candidate->priority > $current->priority;
        }

        $candidateLength = $candidate->dateRange->nights();
        $currentLength = $current->dateRange->nights();

        if ($candidateLength !== $currentLength) {
            return $candidateLength < $currentLength;
        }

        if ($candidate->createdAt != $current->createdAt) {
            return $candidate->createdAt > $current->createdAt;
        }

        return strcmp($candidate->id, $current->id) > 0;
    }
}
