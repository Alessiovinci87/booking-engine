<?php

namespace App\Domain\Payments\Gateway;

use Carbon\CarbonImmutable;

/**
 * PaymentIntent riletto dal fornitore (riconciliazione di 05, eventi payment_intent.*): stato,
 * importo incassato, scadenza dell'autorizzazione (dalla Charge) e codice dell'ultimo errore.
 */
final readonly class PaymentIntentState
{
    /**
     * @param  array<string, string>  $metadata
     */
    public function __construct(
        public string $id,
        public PaymentIntentStatus $status,
        public int $amountCents,
        public int $amountReceivedCents,
        public array $metadata = [],
        public ?CarbonImmutable $captureBefore = null,
        public ?string $failureCode = null,
    ) {}
}
