<?php

namespace App\Domain\Payments\Gateway\Fake;

use App\Domain\Payments\Gateway\ConnectedAccounts;
use App\Domain\Payments\Gateway\ConnectedAccountState;
use App\Domain\Payments\Gateway\Exceptions\FakeGatewayNotAllowed;

/**
 * Account connessi finti per demo e test (solo local e testing, come FakePaymentGateway): l'account
 * `acct_demo_…` risulta subito abilitato e il link di onboarding riporta direttamente al pannello.
 */
final class FakeConnectedAccounts implements ConnectedAccounts
{
    public function __construct(string $environment)
    {
        if (! FakePaymentGateway::allowedIn($environment)) {
            throw FakeGatewayNotAllowed::inEnvironment($environment);
        }
    }

    public function create(string $tenantId, string $displayName, ?string $contactEmail): ConnectedAccountState
    {
        return $this->retrieve('acct_demo_'.substr(hash('sha256', $tenantId), 0, 16));
    }

    public function onboardingLink(string $accountId, string $refreshUrl, string $returnUrl): string
    {
        return $returnUrl;
    }

    public function retrieve(string $accountId): ConnectedAccountState
    {
        return new ConnectedAccountState($accountId, true, true, true, false);
    }
}
