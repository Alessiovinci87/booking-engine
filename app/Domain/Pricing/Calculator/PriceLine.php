<?php

namespace App\Domain\Pricing\Calculator;

use App\Domain\Pricing\Enums\PriceLineKind;

/**
 * Riga di prezzo (04): NIGHT ed EXTRA_GUEST hanno la data della notte; amount = quantity × unitAmount
 * (negativo solo per DISCOUNT).
 */
final readonly class PriceLine
{
    public function __construct(
        public PriceLineKind $kind,
        public ?string $date,
        public int $quantity,
        public int $unitAmountCents,
        public int $amountCents,
        public ?string $seasonId = null,
    ) {}

    /**
     * @return array{kind: string, date: string|null, quantity: int, unit_amount_cents: int, amount_cents: int, season_id: string|null}
     */
    public function toArray(): array
    {
        return [
            'kind' => $this->kind->value,
            'date' => $this->date,
            'quantity' => $this->quantity,
            'unit_amount_cents' => $this->unitAmountCents,
            'amount_cents' => $this->amountCents,
            'season_id' => $this->seasonId,
        ];
    }
}
