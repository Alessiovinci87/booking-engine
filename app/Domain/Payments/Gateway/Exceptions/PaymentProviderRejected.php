<?php

namespace App\Domain\Payments\Gateway\Exceptions;

/**
 * Richiesta rifiutata dal fornitore per un motivo non transitorio (parametri, configurazione
 * dell'account, stato dell'oggetto). Ripeterla identica non serve. Il codice è quello del fornitore.
 */
final class PaymentProviderRejected extends PaymentGatewayException
{
    public function __construct(public readonly string $operation, public readonly ?string $providerCode)
    {
        parent::__construct("Richiesta rifiutata dal fornitore ({$operation}: ".($providerCode ?? 'sconosciuto').').');
    }
}
