<?php

namespace App\Domain\Tenancy\Http;

use App\Domain\Tenancy\Enums\TenantStatus;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\TenantContext;
use App\Http\Api\ApiError;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Pannello in sola lettura durante l'uscita del cliente (7.1; 09 "Uscita del cliente" punto 1): con il
 * tenant OFFBOARDING o CLOSED le richieste che modificano dati rispondono 409 TENANT_OFFBOARDING, salvo
 * cancellazioni, rifiuti delle richieste, rimborsi, diritti degli ospiti, export e la procedura di uscita.
 * Va dopo il middleware `tenant`: con il contesto aperto legge lo stato lì, in modalità "steps" apre un
 * contesto breve solo per leggerlo.
 */
final class EnsureTenantAcceptsChanges
{
    public function __construct(private readonly TenantContext $tenants) {}

    /** Rotte ammesse durante l'uscita (09: "salvo cancellazioni e rimborsi"). */
    public const ALLOWED_ROUTES = [
        'admin.reservations.cancel',
        'admin.reservations.reject',
        'admin.refunds.retry',
        'admin.refunds.mark-offline',
        'admin.guests.erase',
        'admin.tenant.export',
        'admin.tenant.offboarding.confirm-removed',
    ];

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->isMethodSafe()) {
            return $next($request);
        }

        $status = $this->status($request);

        if (in_array($status, [TenantStatus::OFFBOARDING, TenantStatus::CLOSED], true)
            && ! in_array($request->route()?->getName(), self::ALLOWED_ROUTES, true)) {
            return ApiError::response(409, 'TENANT_OFFBOARDING');
        }

        return $next($request);
    }

    private function status(Request $request): mixed
    {
        if ($this->tenants->hasTenant()) {
            return Tenant::query()->value('status');
        }

        $tenantId = $request->attributes->get(WithTenantContext::TENANT_ATTRIBUTE);

        return is_string($tenantId) ? $this->tenants->run($tenantId, fn () => Tenant::query()->value('status')) : null;
    }
}
