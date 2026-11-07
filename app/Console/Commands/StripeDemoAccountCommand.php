<?php

namespace App\Console\Commands;

use App\Domain\Payments\Gateway\Exceptions\PaymentGatewayException;
use App\Domain\Payments\StripeAccountService;
use Database\Seeders\DemoSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Collega il tenant demo a un account Stripe connesso vero in modalità test (M4, 4.1): crea l'account
 * (o riusa quello già creato con la stessa idempotency key), sostituisce l'account finto del seeder e
 * stampa il link di onboarding ospitato da Stripe, da completare con i dati di prova di Stripe.
 * Non stampa mai chiavi o segreti.
 */
final class StripeDemoAccountCommand extends Command
{
    protected $signature = 'stripe:demo-account {--tenant='.DemoSeeder::TENANT_SLUG.' : Slug del tenant}';

    protected $description = 'Crea l\'account Stripe connesso (modalità test) del tenant demo e stampa il link di onboarding';

    public function handle(StripeAccountService $accounts): int
    {
        if (config('payments.gateway') !== 'stripe') {
            $this->error(__('payments.demo_account.gateway_required'));

            return self::FAILURE;
        }

        if (str_starts_with((string) config('services.stripe.secret'), 'sk_live_')) {
            $this->error(__('payments.demo_account.live_key'));

            return self::FAILURE;
        }

        $slug = (string) $this->option('tenant');
        $tenantId = DB::selectOne('select app_tenant_by_slug(?) as id', [$slug])?->id;

        if (! is_string($tenantId)) {
            $this->error(__('payments.demo_account.tenant_missing', ['slug' => $slug]));

            return self::FAILURE;
        }

        $page = rtrim((string) config('app.url'), '/').'/app/pagamenti';

        try {
            $url = $accounts->onboardingLink($tenantId, $page.'?stripe=refresh', $page.'?stripe=return', replaceDemo: true);
        } catch (PaymentGatewayException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $account = $accounts->current($tenantId);
        $this->info(__('payments.demo_account.created', ['account' => (string) $account?->stripe_account_id]));
        $this->line(__('payments.demo_account.link'));
        $this->line($url);

        return self::SUCCESS;
    }
}
