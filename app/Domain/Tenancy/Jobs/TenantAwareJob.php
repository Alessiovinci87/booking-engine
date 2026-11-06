<?php

namespace App\Domain\Tenancy\Jobs;

/**
 * Job in coda che appartiene a un tenant. Il tenant_id viene serializzato con il job quando è già
 * verificato (record padre, webhook risolto) e reimpostato all'avvio da SetTenantContextForJob.
 */
interface TenantAwareJob
{
    public function tenantId(): string;
}
