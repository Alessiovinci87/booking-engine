<?php

namespace App\Domain\Payments\Gateway;

/**
 * Stato di un account connesso nei termini di StripeAccount (02): incassi abilitati, bonifici
 * abilitati, dati inviati, modalità live.
 */
final readonly class ConnectedAccountState
{
    public function __construct(
        public string $id,
        public bool $chargesEnabled,
        public bool $payoutsEnabled,
        public bool $detailsSubmitted,
        public bool $livemode,
    ) {}
}
