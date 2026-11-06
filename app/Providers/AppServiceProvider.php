<?php

namespace App\Providers;

use App\Domain\Tenancy\Auth\PwnedPasswordsVerifier;
use App\Domain\Tenancy\MembershipTenantResolver;
use App\Domain\Tenancy\Outbox\AdminOpsPendingOutboxMessages;
use App\Domain\Tenancy\Outbox\PendingOutboxMessages;
use App\Domain\Tenancy\TenantContext;
use App\Domain\Tenancy\TenantResolver;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\Validation\UncompromisedVerifier;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Un'istanza per richiesta o job: lo stato del tenant corrente non attraversa unità di lavoro.
        $this->app->scoped(TenantContext::class);

        // Pannello: tenant dalla membership dell'utente autenticato (08). Senza utente o membership
        // il risolutore restituisce null e il middleware risponde 404 (fail-closed). La superficie
        // pubblica (slug) avrà un proprio risolutore in M3.
        $this->app->scoped(TenantResolver::class, MembershipTenantResolver::class);

        // Dispatcher dell'outbox: lettura cross-tenant degli ID con app_admin_ops (08).
        $this->app->bind(PendingOutboxMessages::class, AdminOpsPendingOutboxMessages::class);
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
    }
}
