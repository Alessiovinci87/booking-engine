<?php

use App\Domain\Payments\Models\StripeAccount;
use App\Domain\Payments\StripeAccountService;
use Illuminate\Support\Facades\DB;

/*
 * 4.1 — Onboarding Stripe Connect (05 "Configurazione Stripe Connect", 07 "Pagamenti e account"):
 * account v2 con dashboard completa, commissioni e perdite a carico di Stripe, capability
 * card_payments; link ospitato da Stripe; stato aggiornato solo da ciò che si rilegge.
 */

beforeEach(function () {
    $this->stripe = fakeStripe();
});
afterEach(fn () => resetStripeHttp());

function accountOf(string $tenant): ?StripeAccount
{
    return inTenant($tenant, fn () => StripeAccount::query()->first());
}

it('crea l\'account connesso v2 con la configurazione di 05 e restituisce il link di onboarding', function () {
    $owner = signedInOwner($this);
    $this->stripe
        ->on('post', '/v2/core/accounts', stripeV2Account('acct_test_new', 'inactive', 'inactive', 'currently_due'))
        ->on('post', '/v2/core/account_links', ['object' => 'v2.core.account_link', 'account' => 'acct_test_new', 'url' => 'https://connect.stripe.com/setup/e/acct_test_new/abc', 'expires_at' => '2026-11-07T01:00:00.000Z']);

    panelPost($this, '/api/v1/admin/stripe/onboarding-link')
        ->assertOk()
        ->assertJsonPath('data.url', 'https://connect.stripe.com/setup/e/acct_test_new/abc');

    $create = $this->stripe->requestsTo('post', '/v2/core/accounts')[0];
    expect($create['idempotency_key'])->toBe('account:'.$owner['tenant'])
        ->and($create['account'])->toBeNull()
        ->and($create['params']['dashboard'])->toBe('full')
        ->and($create['params']['defaults']['responsibilities'])->toBe(['fees_collector' => 'stripe', 'losses_collector' => 'stripe'])
        ->and($create['params']['configuration']['merchant']['capabilities']['card_payments'])->toBe(['requested' => true])
        ->and($create['params']['metadata'])->toBe(['tenant_id' => $owner['tenant']]);

    $link = $this->stripe->requestsTo('post', '/v2/core/account_links')[0];
    expect($link['params']['account'])->toBe('acct_test_new')
        ->and($link['params']['use_case']['type'])->toBe('account_onboarding')
        ->and($link['params']['use_case']['account_onboarding']['return_url'])->toEndWith('/app/pagamenti?stripe=return');

    $account = accountOf($owner['tenant']);
    expect($account->stripe_account_id)->toBe('acct_test_new')
        ->and($account->charges_enabled)->toBeFalse()
        ->and($account->details_submitted)->toBeFalse();

    expect(inTenant($owner['tenant'], fn () => DB::table('audit_log')->where('action', 'stripe_account.connected')->exists()))->toBeTrue();
});

it('un secondo link non crea un altro account', function () {
    $owner = signedInOwner($this);
    $this->stripe
        ->on('post', '/v2/core/accounts', stripeV2Account('acct_test_once', 'inactive', 'inactive', 'currently_due'))
        ->on('post', '/v2/core/account_links', ['object' => 'v2.core.account_link', 'url' => 'https://connect.stripe.com/setup/x']);

    panelPost($this, '/api/v1/admin/stripe/onboarding-link')->assertOk();
    panelPost($this, '/api/v1/admin/stripe/onboarding-link')->assertOk();

    expect($this->stripe->requestsTo('post', '/v2/core/accounts'))->toHaveCount(1)
        ->and($this->stripe->requestsTo('post', '/v2/core/account_links'))->toHaveCount(2)
        ->and(inTenant($owner['tenant'], fn () => StripeAccount::query()->count()))->toBe(1);
});

it('il rientro dall\'onboarding rilegge lo stato da Stripe', function () {
    $owner = signedInOwner($this);
    inTenant($owner['tenant'], fn () => DB::table('stripe_accounts')->insert([
        'id' => uuid7(), 'tenant_id' => $owner['tenant'], 'stripe_account_id' => 'acct_test_sync', 'created_at' => now(), 'updated_at' => now(),
    ]));
    $this->stripe->on('get', '/v2/core/accounts/acct_test_sync', stripeV2Account('acct_test_sync'));

    panelGet($this, '/api/v1/admin/stripe/account')->assertOk()->assertJsonPath('data.status', 'ONBOARDING_INCOMPLETE');
    expect($this->stripe->requests)->toBe([]);

    panelGet($this, '/api/v1/admin/stripe/account?refresh=1')
        ->assertOk()
        ->assertJsonPath('data.status', 'ENABLED')
        ->assertJsonPath('data.charges_enabled', true)
        ->assertJsonPath('data.payouts_enabled', true);

    expect($this->stripe->requestsTo('get', '/v2/core/accounts/acct_test_sync')[0]['params']['include'])->toBe(['configuration.merchant', 'requirements']);
});

it('senza account il pannello mostra "non collegato"', function () {
    signedInOwner($this);

    panelGet($this, '/api/v1/admin/stripe/account')->assertOk()->assertJsonPath('data', ['connected' => false, 'status' => 'NOT_CONNECTED']);
});

it('Stripe non raggiungibile → 503, configurazione rifiutata → 502', function () {
    signedInOwner($this);
    $this->stripe->fail('post', '/v2/core/accounts', 500, 'api_error', times: 1);
    panelPost($this, '/api/v1/admin/stripe/onboarding-link')->assertStatus(503)->assertJsonPath('error.code', 'PAYMENT_PROVIDER_UNAVAILABLE');

    $this->stripe->fail('post', '/v2/core/accounts', 400, 'invalid_request_error', 'parameter_unknown');
    panelPost($this, '/api/v1/admin/stripe/onboarding-link')->assertStatus(502)->assertJsonPath('error.code', 'PAYMENT_PROVIDER_REJECTED');
});

it('stripe:demo-account sostituisce l\'account finto del seeder e stampa il link senza segreti', function () {
    $tenant = createTenantRow('demo-alghero');
    inTenant($tenant, fn () => DB::table('stripe_accounts')->insert([
        'id' => uuid7(), 'tenant_id' => $tenant, 'stripe_account_id' => StripeAccountService::DEMO_PREFIX.'abc', 'charges_enabled' => true,
        'created_at' => now(), 'updated_at' => now(),
    ]));
    $this->stripe
        ->on('post', '/v2/core/accounts', stripeV2Account('acct_test_demo', 'inactive', 'inactive', 'currently_due'))
        ->on('post', '/v2/core/account_links', ['object' => 'v2.core.account_link', 'url' => 'https://connect.stripe.com/setup/e/acct_test_demo/xyz']);

    $this->artisan('stripe:demo-account')
        ->expectsOutputToContain('https://connect.stripe.com/setup/e/acct_test_demo/xyz')
        ->expectsOutputToContain('acct_test_demo')
        ->doesntExpectOutputToContain('sk_test_simulated')
        ->assertSuccessful();

    $account = accountOf($tenant);
    expect($account->stripe_account_id)->toBe('acct_test_demo')->and($account->charges_enabled)->toBeFalse();
});

it('stripe:demo-account --account collega un account già completato, con lo stato riletto da Stripe', function () {
    $tenant = createTenantRow('demo-alghero');
    inTenant($tenant, fn () => DB::table('stripe_accounts')->insert([
        'id' => uuid7(), 'tenant_id' => $tenant, 'stripe_account_id' => StripeAccountService::DEMO_PREFIX.'abc', 'charges_enabled' => true,
        'created_at' => now(), 'updated_at' => now(),
    ]));
    $this->stripe->on('get', '/v2/core/accounts/acct_test_ready', stripeV2Account('acct_test_ready'));

    $this->artisan('stripe:demo-account', ['--account' => 'acct_test_ready'])
        ->expectsOutputToContain('acct_test_ready')
        ->doesntExpectOutputToContain('sk_test_simulated')
        ->assertSuccessful();

    $account = accountOf($tenant);
    expect($account->stripe_account_id)->toBe('acct_test_ready')->and($account->charges_enabled)->toBeTrue()
        ->and($this->stripe->requestsTo('post', '/v2/core/accounts'))->toBe([]);
});

it('stripe:demo-account rifiuta il gateway finto e le chiavi live', function () {
    config(['payments.gateway' => 'fake']);
    $this->artisan('stripe:demo-account')->assertFailed();

    config(['payments.gateway' => 'stripe', 'services.stripe.secret' => 'sk_live_xxx']);
    $this->artisan('stripe:demo-account')->assertFailed();
    expect($this->stripe->requests)->toBe([]);
});
