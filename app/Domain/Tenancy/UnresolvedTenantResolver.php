<?php

namespace App\Domain\Tenancy;

use Illuminate\Http\Request;

/**
 * Risolutore predefinito finché non esistono membership e slug (M1-M2): nessun tenant, quindi le rotte
 * protette dal middleware rispondono 404 (fail-closed).
 */
final class UnresolvedTenantResolver implements TenantResolver
{
    public function resolve(Request $request): ?string
    {
        return null;
    }
}
