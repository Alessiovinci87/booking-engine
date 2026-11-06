<?php

namespace App\Domain\Payments\Gateway\Exceptions;

/** Rifiuto definitivo del fornitore (capture, rimborso): non si ripete con la stessa chiave. */
final class PaymentDeclined extends PaymentGatewayException
{
    public function __construct(public readonly string $declineCode)
    {
        parent::__construct("Operazione rifiutata dal fornitore ({$declineCode}).");
    }
}
