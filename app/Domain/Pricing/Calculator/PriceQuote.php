<?php

namespace App\Domain\Pricing\Calculator;

use App\Domain\Inventory\Enums\PaymentMode;

/**
 * Risultato del calcolo (04): righe, subtotale, sconto, totale, caparra e saldo, tutti interi in
 * centesimi, con deposit + balance = total.
 */
final readonly class PriceQuote
{
    /**
     * @param  list<PriceLine>  $lines
     */
    public function __construct(
        public array $lines,
        public int $nights,
        public int $guests,
        public int $requiredMinNights,
        public int $subtotalCents,
        public int $discountCents,
        public int $totalCents,
        public int $depositCents,
        public int $balanceCents,
        public PaymentMode $paymentMode,
        public string $currency,
    ) {}
}
