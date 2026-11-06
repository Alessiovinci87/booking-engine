<?php

namespace App\Domain\Pricing\Calculator;

use App\Domain\Tenancy\Exceptions\BusinessRuleViolation;

/** Errori del calcolo (04, 07): 422 con i valori che servono a spiegarli, mai dati personali. */
final class PricingError extends BusinessRuleViolation
{
    public const STRIPE_MINIMUM_CENTS = 50;

    public static function minNightsNotMet(int $required, int $nights): self
    {
        return new self(422, 'MIN_NIGHTS_NOT_MET', ['required_min_nights' => $required, 'nights' => $nights]);
    }

    public static function occupancyExceeded(int $max, int $guests): self
    {
        return new self(422, 'OCCUPANCY_EXCEEDED', ['max_occupancy' => $max, 'guests' => $guests]);
    }

    public static function amountBelowMinimum(int $total): self
    {
        return new self(422, 'AMOUNT_BELOW_MINIMUM', ['minimum_cents' => self::STRIPE_MINIMUM_CENTS, 'total_cents' => $total]);
    }

    public static function discountOutOfRange(int $discount, int $subtotal): self
    {
        return new self(400, 'VALIDATION_FAILED', [
            'fields' => ['discount_cents' => ['DISCOUNT_OUT_OF_RANGE']],
            'subtotal_cents' => $subtotal,
            'discount_cents' => $discount,
        ]);
    }
}
