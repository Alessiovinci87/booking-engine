<?php

namespace App\Domain\Payments\Gateway;

use Carbon\CarbonImmutable;

/**
 * Sessione riletta dal fornitore con il PaymentIntent collegato. paymentFailed indica un tentativo
 * di pagamento rifiutato definitivamente (evento payment_intent.payment_failed); captureBefore viene
 * dalla Charge per le autorizzazioni (05, flusso B).
 */
final readonly class CheckoutSessionState
{
    /**
     * @param  array<string, string>  $metadata
     */
    public function __construct(
        public string $id,
        public CheckoutSessionStatus $status,
        public CarbonImmutable $expiresAt,
        public int $amountCents,
        public array $metadata,
        public ?string $paymentIntentId = null,
        public ?PaymentIntentStatus $paymentIntentStatus = null,
        public bool $paymentFailed = false,
        public ?string $failureCode = null,
        public ?CarbonImmutable $captureBefore = null,
    ) {}

    public function isPaid(): bool
    {
        return $this->paymentIntentStatus === PaymentIntentStatus::SUCCEEDED;
    }

    public function isAuthorized(): bool
    {
        return $this->paymentIntentStatus === PaymentIntentStatus::REQUIRES_CAPTURE;
    }
}
