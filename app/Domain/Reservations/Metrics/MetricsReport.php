<?php

namespace App\Domain\Reservations\Metrics;

use Carbon\CarbonImmutable;

/**
 * Risultato di MetricsService (01): importi in centesimi. Il beneficio si chiama sempre "beneficio
 * diretto stimato", mai "risparmio netto".
 */
final readonly class MetricsReport
{
    /**
     * @param  list<array{property_id: string, name: string, ota_commission_bps: int, reservations: int, direct_revenue_cents: int, ota_commission_avoided_cents: int}>  $properties
     */
    public function __construct(
        public CarbonImmutable $from,
        public CarbonImmutable $to,
        public int $reservations,
        public int $nights,
        public int $directRevenueCents,
        public int $otaCommissionAvoidedCents,
        public int $paymentFeesCents,
        public bool $paymentFeesEstimated,
        public int $directDiscountsCents,
        public array $properties,
    ) {}

    public function estimatedDirectBenefitCents(): int
    {
        return $this->otaCommissionAvoidedCents - $this->paymentFeesCents - $this->directDiscountsCents;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'from' => $this->from->toDateString(),
            'to' => $this->to->toDateString(),
            'currency' => 'EUR',
            'reservations' => $this->reservations,
            'nights' => $this->nights,
            'direct_revenue_cents' => $this->directRevenueCents,
            'ota_commission_avoided_cents' => $this->otaCommissionAvoidedCents,
            'payment_fees_cents' => $this->paymentFeesCents,
            'payment_fees_estimated' => $this->paymentFeesEstimated,
            'direct_discounts_cents' => $this->directDiscountsCents,
            'estimated_direct_benefit_cents' => $this->estimatedDirectBenefitCents(),
            'properties' => $this->properties,
        ];
    }
}
