<?php

namespace App\Http\Controllers;

use App\Domain\Inventory\PublicCatalog;
use App\Domain\Tenancy\PublicSlugTenantResolver;
use App\Domain\Tenancy\TenantContext;
use App\Http\Middleware\CheckPublicOrigin;
use App\Http\Middleware\SecurityHeaders;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

/**
 * Pagina ospite (SPA) e vista embed (08, ADR-0009):
 *
 * - /p/{t}/{p}: pagina normale, non incorporabile (`frame-ancestors 'none'`);
 * - /e/{t}/{p}: stessa SPA da incorporare in un iframe solo nei domini registrati dal gestore
 *   (`frame-ancestors` = embed_allowed_origins del tenant; nessuno registrato → 'none'). Struttura
 *   inesistente o non pubblicata → 404. Nessun cookie di sessione (rotta senza sessione) e pagamento
 *   sempre top-level (la SPA lo apre fuori dall'iframe).
 */
final class GuestPageController extends Controller
{
    public function page(): View
    {
        return view('guest', ['embed' => false]);
    }

    public function embed(Request $request, string $tenantSlug, string $propertySlug, PublicSlugTenantResolver $resolver, TenantContext $tenants, PublicCatalog $catalog): View
    {
        $tenantId = $resolver->resolve($request) ?? abort(404);
        $tenants->run($tenantId, fn () => $catalog->property($propertySlug));

        $request->attributes->set(SecurityHeaders::EMBED_ANCESTORS_ATTRIBUTE, CheckPublicOrigin::allowedFor($tenants, $tenantId));

        return view('guest', ['embed' => true]);
    }
}
