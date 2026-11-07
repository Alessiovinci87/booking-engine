<?php

namespace App\Domain\Payments\Gateway;

/**
 * Contestazione (chargeback) riletta dal fornitore (05 "Webhook": charge.dispute.*). Non cambia lo
 * stato della prenotazione né del pagamento (03): genera un PaymentEvent e un'allerta al gestore.
 */
final readonly class DisputeState
{
    public function __construct(
        public string $id,
        public ?string $paymentIntentId,
        public int $amountCents,
        public string $status,
        public ?string $reason,
    ) {}

    /** Esito chiuso (vinta, persa o ritirata). */
    public function isClosed(): bool
    {
        return in_array($this->status, ['won', 'lost', 'warning_closed', 'prevented'], true);
    }
}
