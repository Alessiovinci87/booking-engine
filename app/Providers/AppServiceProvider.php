<?php

namespace App\Providers;

use App\Domain\Tenancy\TenantContext;
use App\Domain\Tenancy\TenantResolver;
use App\Domain\Tenancy\UnresolvedTenantResolver;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Un'istanza per richiesta o job: lo stato del tenant corrente non attraversa unità di lavoro.
        $this->app->scoped(TenantContext::class);

        // Fino a M1-M2 nessun tenant è risolvibile: le rotte protette rispondono 404 (fail-closed).
        $this->app->scoped(TenantResolver::class, UnresolvedTenantResolver::class);
    }

    public function boot(): void
    {
        //
    }
}
