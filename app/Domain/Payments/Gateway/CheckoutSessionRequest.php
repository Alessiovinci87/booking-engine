<?php

namespace App\Domain\Payments\Gateway;

use App\Domain\Payments\Enums\CaptureMethod;
use App\Domain\Tenancy\Enums\Locale;
use Carbon\CarbonImmutable;

/**
 * Parametri della sessione di checkout (05, "Parametri della sessione"). Il metodo di capture deriva
 * dalla prenotazione, mai dal frontend. I metadata contengono solo identificativi (CLAUDE.md regola 11:
 * nessun dato personale nei metadata del fornitore); l'email dell'ospite va solo in customerEmail.
 */
final readonly class CheckoutSessionRequest
{
    /**
     * @param  array{reservation_id: string, payment_id: string, tenant_id: string}  $metadata
     */
    public function __construct(
        public string $accountId,
        public string $idempotencyKey,
        public int $amountCents,
        public string $currency,
        public CaptureMethod $captureMethod,
        public CarbonImmutable $expiresAt,
        public string $description,
        public string $successUrl,
        public string $cancelUrl,
        public Locale $locale,
        public ?string $customerEmail,
        public array $metadata,
    ) {}
}
