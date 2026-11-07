<?php

namespace App\Domain\Payments\Stripe;

use App\Domain\Payments\Gateway\Exceptions\PaymentProviderUnavailable;
use Illuminate\Support\Facades\Log;
use Stripe\Exception\ApiConnectionException;
use Stripe\Exception\ApiErrorException;
use Stripe\Exception\RateLimitException;

/**
 * Traduzione degli errori di stripe-php nelle eccezioni del gateway (PaymentGateway). Nei log solo
 * operazione, tipo d'errore, codice e request ID di Stripe: mai parametri, chiavi o dati personali.
 */
final class StripeErrors
{
    /** Rete, limite di richieste o errore 5xx: esito sconosciuto, si riprova con la stessa chiave (I9). */
    public static function isTransient(ApiErrorException $e): bool
    {
        return $e instanceof ApiConnectionException
            || $e instanceof RateLimitException
            || ($e->getHttpStatus() ?? 500) >= 500;
    }

    public static function unavailable(string $operation, ApiErrorException $e): PaymentProviderUnavailable
    {
        self::log($operation, $e);

        return PaymentProviderUnavailable::make($operation);
    }

    public static function log(string $operation, ApiErrorException $e): void
    {
        Log::warning('stripe.api_error', [
            'operation' => $operation,
            'error' => class_basename($e),
            'http_status' => $e->getHttpStatus(),
            'code' => $e->getStripeCode(),
            'request_id' => $e->getRequestId(),
        ]);
    }
}
