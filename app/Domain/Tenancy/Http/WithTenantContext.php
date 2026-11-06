<?php

namespace App\Domain\Tenancy\Http;

use App\Domain\Tenancy\TenantContext;
use App\Domain\Tenancy\TenantResolver;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Middleware delle richieste admin e pubbliche: risolve il tenant da fonti verificate e gestisce
 * l'intera richiesta dentro TenantContext::run(). Senza tenant risolto risponde 404, mai 403 (08).
 */
final class WithTenantContext
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly TenantResolver $resolver,
    ) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $tenantId = $this->resolver->resolve($request);

        if ($tenantId === null) {
            abort(404);
        }

        return $this->tenantContext->run($tenantId, fn (): Response => $next($request));
    }
}
