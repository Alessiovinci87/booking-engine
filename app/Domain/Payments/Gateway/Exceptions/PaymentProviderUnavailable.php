<?php

namespace App\Domain\Payments\Gateway\Exceptions;

/**
 * Rete, timeout o errore del fornitore: l'esito della chiamata è sconosciuto. Si riprova con la
 * stessa idempotency key (I9); verso il client diventa 503 PAYMENT_PROVIDER_UNAVAILABLE (07).
 */
final class PaymentProviderUnavailable extends PaymentGatewayException
{
    public static function make(string $operation): self
    {
        return new self("Fornitore di pagamento non raggiungibile ({$operation}).");
    }
}
