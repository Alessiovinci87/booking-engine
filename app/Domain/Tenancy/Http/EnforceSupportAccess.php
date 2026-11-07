<?php

namespace App\Domain\Tenancy\Http;

use App\Domain\Tenancy\Models\User;
use App\Domain\Tenancy\Support\SupportAccessService;
use App\Http\Api\ApiError;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Accesso di supporto in sola lettura (7.5; 08; ADR-0018). Va prima del middleware `tenant` in tutti i
 * gruppi del pannello. Per gli utenti senza `is_platform_operator` non fa nulla.
 *
 * Per un operatore:
 * - senza autorizzazione attiva (scaduta o revocata) risponde 403 SUPPORT_ACCESS_INACTIVE;
 * - con un metodo diverso da GET/HEAD/OPTIONS risponde 403 SUPPORT_READ_ONLY: il rifiuto è del server,
 *   non solo dell'interfaccia;
 * - le rotte con ri-autenticazione (export con dati personali, download dell'export completo) restano
 *   chiuse anche in lettura: 403 SUPPORT_READ_ONLY;
 * - altrimenti registra la lettura e passa il tenant dell'autorizzazione a MembershipTenantResolver.
 */
final class EnforceSupportAccess
{
    public const GRANT_ATTRIBUTE = 'support_grant';

    public function __construct(private readonly SupportAccessService $support) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof User || ! $user->is_platform_operator) {
            return $next($request);
        }

        $grant = $this->support->activeGrantFor($user->id);

        if ($grant === null) {
            return ApiError::response(403, 'SUPPORT_ACCESS_INACTIVE');
        }

        if (! $request->isMethodSafe() || self::needsReauthentication($request)) {
            return ApiError::response(403, 'SUPPORT_READ_ONLY');
        }

        $this->support->recordUse($grant['tenant_id'], $grant['grant_id'], $user->id);
        $request->attributes->set(self::GRANT_ATTRIBUTE, $grant);

        return $next($request);
    }

    private static function needsReauthentication(Request $request): bool
    {
        $middleware = $request->route()?->gatherMiddleware() ?? [];

        foreach ($middleware as $name) {
            if (is_string($name) && ($name === 'reauth' || str_starts_with($name, 'signed'))) {
                return true;
            }
        }

        return false;
    }
}
