<?php

namespace App\Domain\Tenancy\Jobs;

/**
 * Implementazione standard di TenantAwareJob: il job assegna $tenantId nel costruttore e ottiene il
 * middleware che esegue handle() dentro TenantContext::run().
 *
 * @phpstan-require-implements TenantAwareJob
 */
trait InteractsWithTenant
{
    public string $tenantId;

    public function tenantId(): string
    {
        return $this->tenantId;
    }

    /**
     * @return list<object>
     */
    public function middleware(): array
    {
        return [new SetTenantContextForJob];
    }
}
