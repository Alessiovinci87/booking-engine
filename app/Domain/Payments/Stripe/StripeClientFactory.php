<?php

namespace App\Domain\Payments\Stripe;

use LogicException;
use Stripe\StripeClient;
use Stripe\Util\ApiVersion;

/**
 * Client dell'API di Stripe con la chiave segreta della piattaforma (05, 08). La versione dell'API è
 * quella fissata dalla libreria; i retry di rete della libreria riusano la stessa idempotency key.
 * La chiave non compare mai nei messaggi d'errore.
 */
final class StripeClientFactory
{
    private ?StripeClient $client = null;

    public function __construct(private readonly ?string $secret) {}

    public function client(): StripeClient
    {
        if ($this->secret === null || $this->secret === '') {
            throw new LogicException('STRIPE_SECRET non configurata.');
        }

        return $this->client ??= new StripeClient([
            'api_key' => $this->secret,
            'stripe_version' => ApiVersion::CURRENT,
            'max_network_retries' => 2,
        ]);
    }
}
