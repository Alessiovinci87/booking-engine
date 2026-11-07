<?php

namespace App\Domain\Payments;

/**
 * Evento del fornitore di pagamento già verificato (firma del webhook in M4, oppure evento del checkout
 * simulato): tipo Stripe, account connesso e oggetto a cui si riferisce. Non contiene dati personali.
 * Chi lo gestisce non si fida del contenuto: rilegge la sessione via API (05, "Webhook", passo 6).
 */
final readonly class PaymentProviderEvent
{
    public const CHECKOUT_COMPLETED = 'checkout.session.completed';

    public const CHECKOUT_EXPIRED = 'checkout.session.expired';

    public const PAYMENT_FAILED = 'payment_intent.payment_failed';

    /**
     * @param  array<string, string>  $metadata
     */
    public function __construct(
        public string $id,
        public string $type,
        public string $accountId,
        public string $checkoutSessionId,
        public array $metadata,
    ) {}
}
