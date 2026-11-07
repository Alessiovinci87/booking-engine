<?php

namespace App\Domain\Payments\Gateway;

use App\Domain\Payments\Gateway\Exceptions\PaymentProviderUnavailable;

/**
 * Account connessi della struttura (05, "Configurazione Stripe Connect"): creazione, link di
 * onboarding ospitato dal fornitore e rilettura dello stato. Implementazioni: StripeConnectedAccounts
 * (Accounts v2, dashboard completa) e FakeConnectedAccounts (solo local e testing).
 */
interface ConnectedAccounts
{
    /**
     * Crea l'account connesso del tenant. Idempotency key `account:{tenant_id}`: ripetuta, restituisce
     * lo stesso account. I metadata contengono solo l'ID del tenant.
     *
     * @throws PaymentProviderUnavailable
     */
    public function create(string $tenantId, string $displayName, ?string $contactEmail): ConnectedAccountState;

    /**
     * Link di onboarding ospitato dal fornitore (monouso, scade in pochi minuti).
     *
     * @throws PaymentProviderUnavailable
     */
    public function onboardingLink(string $accountId, string $refreshUrl, string $returnUrl): string;

    /**
     * Stato dell'account riletto dal fornitore (05: si applica solo ciò che si legge).
     *
     * @throws PaymentProviderUnavailable
     */
    public function retrieve(string $accountId): ConnectedAccountState;
}
