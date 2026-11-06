<?php

namespace App\Providers;

use App\Domain\Tenancy\MembershipTenantResolver;
use App\Domain\Tenancy\TenantContext;
use App\Domain\Tenancy\TenantResolver;
use Illuminate\Cache\RateLimiting\Limit;
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
    }

    public function boot(): void
    {
        // API admin: 300 richieste al minuto per utente (07).
        RateLimiter::for('admin', function (Request $request): Limit {
            $id = $request->user()?->getAuthIdentifier();

            return Limit::perMinute((int) config('security.admin_rate_limit_per_minute'))
                ->by('admin:'.(is_string($id) ? $id : 'ip:'.$request->ip()));
        });
    }
}
