<?php

namespace App\Domain\Pricing;

/**
 * Importi in centesimi interi (CLAUDE.md regola 6). Nell'API: { amount_cents, currency } (07).
 * Nella 1.0 la valuta è sempre EUR.
 */
final class Money
{
    public const CURRENCY = 'EUR';

    /**
     * @return array{amount_cents: int, currency: string}
     */
    public static function json(int $cents, string $currency = self::CURRENCY): array
    {
        return ['amount_cents' => $cents, 'currency' => $currency];
    }
}
