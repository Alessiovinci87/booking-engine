<?php

namespace App\Domain\Tenancy;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Tenant delle richieste pubbliche (07, 08): dallo slug nel percorso /public/{tenantSlug}/…, tramite
 * la funzione SECURITY DEFINER app_tenant_by_slug (le richieste non leggono mai tenants senza
 * contesto). Slug sconosciuto → null, quindi 404.
 */
final class PublicSlugTenantResolver implements TenantResolver
{
    public function resolve(Request $request): ?string
    {
        $slug = $request->route('tenantSlug');

        if (! is_string($slug) || $slug === '') {
            return null;
        }

        $id = DB::selectOne('SELECT app_tenant_by_slug(?) AS id', [$slug])?->id;

        return is_string($id) ? $id : null;
    }
}
