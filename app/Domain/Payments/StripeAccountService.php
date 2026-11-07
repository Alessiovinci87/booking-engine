<?php

namespace App\Domain\Payments;

use App\Domain\Payments\Gateway\ConnectedAccounts;
use App\Domain\Payments\Gateway\ConnectedAccountState;
use App\Domain\Payments\Models\StripeAccount;
use App\Domain\Tenancy\Audit\AuditLogger;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\TenantContext;
use Carbon\CarbonImmutable;

/**
 * Collegamento Stripe Connect della struttura (05, 4.1): un account connesso per tenant, creato al
 * primo onboarding e aggiornato solo da ciò che si rilegge dal fornitore (evento account.updated o
 * rientro dall'onboarding). Le chiamate al fornitore sono fuori dalle transazioni; la creazione usa
 * l'idempotency key `account:{tenant_id}`, quindi una richiesta ripetuta ritrova lo stesso account.
 *
 * Finché charges_enabled è falso la struttura accetta solo prenotazioni manuali (05).
 */
final class StripeAccountService
{
    /** Prefisso degli account finti del seeder demo (ADR-0010 punto 5). */
    public const DEMO_PREFIX = 'acct_demo_';

    public function __construct(
        private readonly TenantContext $tenants,
        private readonly ConnectedAccounts $accounts,
        private readonly AuditLogger $audit,
    ) {}

    public function current(string $tenantId): ?StripeAccount
    {
        return $this->tenants->run($tenantId, fn (): ?StripeAccount => StripeAccount::query()->first());
    }

    /**
     * Link di onboarding ospitato da Stripe; crea l'account connesso se il tenant non ne ha uno.
     * Con replaceDemo un account finto del seeder viene sostituito da uno vero (stripe:demo-account).
     */
    public function onboardingLink(string $tenantId, string $refreshUrl, string $returnUrl, ?string $contactEmail = null, bool $replaceDemo = false): string
    {
        $existing = $this->current($tenantId);

        if ($existing === null || ($replaceDemo && str_starts_with($existing->stripe_account_id, self::DEMO_PREFIX))) {
            $name = $this->tenants->run($tenantId, fn (): string => (string) Tenant::query()->whereKey($tenantId)->value('name'));
            $state = $this->accounts->create($tenantId, $name, $contactEmail);
            $existing = $this->store($tenantId, $state, created: true);
        }

        return $this->accounts->onboardingLink($existing->stripe_account_id, $refreshUrl, $returnUrl);
    }

    /**
     * Rilegge lo stato dal fornitore e lo salva (account.updated, rientro dall'onboarding). Un account
     * finto del seeder non si rilegge.
     */
    public function sync(string $tenantId): ?StripeAccount
    {
        $account = $this->current($tenantId);

        if ($account === null || str_starts_with($account->stripe_account_id, self::DEMO_PREFIX)) {
            return $account;
        }

        return $this->store($tenantId, $this->accounts->retrieve($account->stripe_account_id), created: false);
    }

    private function store(string $tenantId, ConnectedAccountState $state, bool $created): StripeAccount
    {
        return $this->tenants->run($tenantId, function () use ($state, $created): StripeAccount {
            $account = StripeAccount::query()->lockForUpdate()->first() ?? new StripeAccount;
            $previous = $account->exists ? $account->stripe_account_id : null;

            $account->forceFill([
                'stripe_account_id' => $state->id,
                'charges_enabled' => $state->chargesEnabled,
                'payouts_enabled' => $state->payoutsEnabled,
                'details_submitted' => $state->detailsSubmitted,
                'livemode' => $state->livemode,
                'last_synced_at' => CarbonImmutable::now(),
            ]);

            if (! $account->isDirty(['stripe_account_id', 'charges_enabled', 'payouts_enabled', 'details_submitted', 'livemode'])) {
                $account->save();

                return $account;
            }

            $account->save();
            $this->audit->record($created ? 'stripe_account.connected' : 'stripe_account.updated', 'stripe_account', $account->id, [
                'stripe_account_id' => $previous === $state->id ? $state->id : ['from' => $previous, 'to' => $state->id],
                'charges_enabled' => $state->chargesEnabled,
                'payouts_enabled' => $state->payoutsEnabled,
                'details_submitted' => $state->detailsSubmitted,
            ]);

            return $account;
        });
    }
}
