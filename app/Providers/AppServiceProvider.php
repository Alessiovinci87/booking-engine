<?php

namespace App\Providers;

use App\Domain\Payments\Gateway\Fake\FakePaymentGateway;
use App\Domain\Payments\Gateway\PaymentGateway;
use App\Domain\Tenancy\Auth\PwnedPasswordsVerifier;
use App\Domain\Tenancy\MembershipTenantResolver;
use App\Domain\Tenancy\Outbox\AdminOpsPendingOutboxMessages;
use App\Domain\Tenancy\Outbox\PendingOutboxMessages;
use App\Domain\Tenancy\TenantContext;
use App\Domain\Tenancy\TenantResolver;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\Validation\UncompromisedVerifier;
use Illuminate\Foundation\Console\ServeCommand;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use LogicException;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Un'istanza per richiesta o job: lo stato del tenant corrente non attraversa unità di lavoro.
        $this->app->scoped(TenantContext::class);

        // Pannello: tenant dalla membership dell'utente autenticato (08). Senza utente o membership
        // il risolutore restituisce null e il middleware risponde 404 (fail-closed). La superficie
        // pubblica usa PublicSlugTenantResolver tramite il middleware tenant:public.
        $this->app->scoped(TenantResolver::class, MembershipTenantResolver::class);

        // Dispatcher dell'outbox: lettura cross-tenant degli ID con app_admin_ops (08).
        $this->app->bind(PendingOutboxMessages::class, AdminOpsPendingOutboxMessages::class);

        // Fornitore di pagamento (05). Il gateway finto rifiuta di partire fuori da local e testing;
        // StripePaymentGateway arriva in M4 (attività 4.2).
        $this->app->singleton(PaymentGateway::class, function ($app): PaymentGateway {
            return match (config('payments.gateway')) {
                'fake' => new FakePaymentGateway($app['cache']->store(), $app->environment(), (string) config('app.url')),
                default => throw new LogicException('Gateway di pagamento non disponibile: '.var_export(config('payments.gateway'), true).'.'),
            };
        });

        // Solo sviluppo: `php artisan serve` passa al server PHP un elenco chiuso di variabili e su
        // Windows, senza TEMP/TMP, il server non riesce a creare i file temporanei degli upload
        // ("unable to create a temporary file"): le foto non si caricherebbero.
        if ($this->app->runningInConsole() && $this->app->environment('local')) {
            ServeCommand::$passthroughVariables = array_values(array_unique([...ServeCommand::$passthroughVariables, 'TEMP', 'TMP']));
        }
    }

    public function boot(): void
    {
        // Password compromesse: fail-open con avviso nel log (08, decisione dopo la verifica di M1).
        // extend e non bind: il provider di validazione è differito e sostituirebbe il binding.
        $this->app->extend(
            UncompromisedVerifier::class,
            fn (): UncompromisedVerifier => $this->app->make(PwnedPasswordsVerifier::class),
        );

        // API admin: 300 richieste al minuto per utente (07).
        RateLimiter::for('admin', function (Request $request): Limit {
            $id = $request->user()?->getAuthIdentifier();

            return Limit::perMinute((int) config('security.admin_rate_limit_per_minute'))
                ->by('admin:'.(is_string($id) ? $id : 'ip:'.$request->ip()));
        });

        // API pubblica in lettura: 120 richieste al minuto per IP (07).
        RateLimiter::for('public-read', fn (Request $request): Limit => Limit::perMinute((int) config('security.public_read_rate_limit_per_minute'))
            ->by('public-read:'.$request->ip()));
    }
}
