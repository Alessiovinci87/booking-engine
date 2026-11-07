<?php

namespace App\Domain\Tenancy;

use App\Domain\Quotes\QuoteLinks;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Tenant di /public/quotes/{token} (07): dalla funzione SECURITY DEFINER app_tenant_by_quote, che
 * risponde solo all'hash di un token esistente. Token mancante, malformato o sconosciuto → null,
 * quindi 404 (mai 403, 08).
 */
final class QuoteTokenTenantResolver implements TenantResolver
{
    public function resolve(Request $request): ?string
    {
        $token = $request->route('token');

        if (! is_string($token) || preg_match('/^'.QuoteLinks::TOKEN_PATTERN.'$/', $token) !== 1) {
            return null;
        }

        $id = DB::selectOne('SELECT app_tenant_by_quote(?) AS id', [QuoteLinks::hash($token)])?->id;

        return is_string($id) ? $id : null;
    }
}
