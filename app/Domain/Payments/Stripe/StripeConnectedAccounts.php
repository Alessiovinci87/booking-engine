<?php

namespace App\Domain\Payments\Stripe;

use App\Domain\Payments\Gateway\ConnectedAccounts;
use App\Domain\Payments\Gateway\ConnectedAccountState;
use App\Domain\Payments\Gateway\Exceptions\PaymentProviderRejected;
use Stripe\Exception\ApiErrorException;
use Stripe\V2\Core\Account;

/**
 * Account connessi con l'API Accounts v2 (05, ADR-0012): configurazione merchant con card_payments,
 * dashboard Stripe completa, commissioni e saldi negativi a carico di Stripe e della struttura
 * (fees_collector e losses_collector "stripe"). Onboarding con Account Links ospitati da Stripe.
 *
 * Corrispondenza con i campi di StripeAccount (02):
 * - charges_enabled  = capability merchant card_payments "active";
 * - payouts_enabled  = capability merchant stripe_balance.payouts "active";
 * - details_submitted = nessun requisito "currently_due" o "past_due" in attesa della struttura.
 */
final class StripeConnectedAccounts implements ConnectedAccounts
{
    private const INCLUDE = ['configuration.merchant', 'requirements'];

    public function __construct(
        private readonly StripeClientFactory $stripe,
        private readonly string $country,
    ) {}

    public function create(string $tenantId, string $displayName, ?string $contactEmail): ConnectedAccountState
    {
        $params = array_filter([
            'display_name' => mb_substr($displayName, 0, 100),
            'contact_email' => $contactEmail,
            'dashboard' => 'full',
            'identity' => ['country' => $this->country],
            'defaults' => [
                'currency' => 'eur',
                'locales' => ['it-IT'],
                'responsibilities' => ['fees_collector' => 'stripe', 'losses_collector' => 'stripe'],
            ],
            'configuration' => ['merchant' => ['capabilities' => ['card_payments' => ['requested' => true]]]],
            'include' => self::INCLUDE,
            'metadata' => ['tenant_id' => $tenantId],
        ], fn ($value): bool => $value !== null);

        return $this->call('accounts.create', fn (): ConnectedAccountState => $this->state(
            $this->stripe->client()->v2->core->accounts->create($params, ['idempotency_key' => 'account:'.$tenantId]),
        ));
    }

    public function onboardingLink(string $accountId, string $refreshUrl, string $returnUrl): string
    {
        return $this->call('account_links.create', fn (): string => (string) $this->stripe->client()->v2->core->accountLinks->create([
            'account' => $accountId,
            'use_case' => [
                'type' => 'account_onboarding',
                'account_onboarding' => ['refresh_url' => $refreshUrl, 'return_url' => $returnUrl],
            ],
        ])->url);
    }

    public function retrieve(string $accountId): ConnectedAccountState
    {
        return $this->call('accounts.retrieve', fn (): ConnectedAccountState => $this->state(
            $this->stripe->client()->v2->core->accounts->retrieve($accountId, ['include' => self::INCLUDE]),
        ));
    }

    private function state(Account $account): ConnectedAccountState
    {
        $data = $account->toArray();
        $capabilities = $data['configuration']['merchant']['capabilities'] ?? [];
        $deadline = $data['requirements']['summary']['minimum_deadline']['status'] ?? null;

        return new ConnectedAccountState(
            id: $account->id,
            chargesEnabled: ($capabilities['card_payments']['status'] ?? null) === 'active',
            payoutsEnabled: ($capabilities['stripe_balance']['payouts']['status'] ?? null) === 'active',
            detailsSubmitted: ! in_array($deadline, ['currently_due', 'past_due'], true),
            livemode: (bool) ($data['livemode'] ?? false),
        );
    }

    /**
     * @template T
     *
     * @param  callable(): T  $call
     * @return T
     */
    private function call(string $operation, callable $call): mixed
    {
        try {
            return $call();
        } catch (ApiErrorException $e) {
            if (StripeErrors::isTransient($e)) {
                throw StripeErrors::unavailable($operation, $e);
            }

            StripeErrors::log($operation, $e);

            throw new PaymentProviderRejected($operation, $e->getStripeCode());
        }
    }
}
