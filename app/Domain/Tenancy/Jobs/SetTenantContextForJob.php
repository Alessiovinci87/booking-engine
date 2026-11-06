<?php

namespace App\Domain\Tenancy\Jobs;

use App\Domain\Tenancy\TenantContext;
use Closure;
use Illuminate\Container\Container;

/**
 * Middleware dei job: esegue il job dentro TenantContext::run() con il tenant serializzato.
 */
final class SetTenantContextForJob
{
    /**
     * @param  Closure(TenantAwareJob): mixed  $next
     */
    public function handle(TenantAwareJob $job, Closure $next): mixed
    {
        return Container::getInstance()
            ->make(TenantContext::class)
            ->run($job->tenantId(), fn (): mixed => $next($job));
    }
}
