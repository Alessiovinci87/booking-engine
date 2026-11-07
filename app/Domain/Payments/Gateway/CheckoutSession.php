<?php

namespace App\Domain\Payments\Gateway;

use Carbon\CarbonImmutable;

/**
 * Sessione creata: expiresAt è il valore restituito dal fornitore, da salvare in
 * payments.checkout_expires_at (05, passo 3).
 */
final readonly class CheckoutSession
{
    public function __construct(
        public string $id,
        public string $url,
        public CarbonImmutable $expiresAt,
    ) {}
}
