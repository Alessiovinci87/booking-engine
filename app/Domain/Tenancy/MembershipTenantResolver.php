<?php

namespace App\Domain\Tenancy;

use App\Domain\Tenancy\Auth\UserTenants;
use App\Domain\Tenancy\Http\EnforceSupportAccess;
use App\Domain\Tenancy\Models\User;
use Illuminate\Http\Request;

/**
 * Tenant delle richieste del pannello (08): dalla membership dell'utente autenticato, mai dal client.
 * Senza utente o senza membership restituisce null (il middleware risponde 404).
 *
 * Un operatore di piattaforma (7.5) non ha membership: il tenant è solo quello dell'autorizzazione di
 * supporto attiva, verificata da EnforceSupportAccess prima di questo resolver.
 */
final class MembershipTenantResolver implements TenantResolver
{
    public function __construct(private readonly UserTenants $userTenants) {}

    public function resolve(Request $request): ?string
    {
        $user = $request->user();

        if ($user instanceof User && $user->is_platform_operator) {
            $grant = $request->attributes->get(EnforceSupportAccess::GRANT_ATTRIBUTE);

            return is_array($grant) && is_string($grant['tenant_id'] ?? null) ? $grant['tenant_id'] : null;
        }

        $id = $user?->getAuthIdentifier();

        return is_string($id) ? $this->userTenants->primaryOf($id) : null;
    }
}
