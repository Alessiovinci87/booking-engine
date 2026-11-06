<?php

namespace App\Domain\Tenancy;

use App\Domain\Tenancy\Auth\UserTenants;
use Illuminate\Http\Request;

/**
 * Tenant delle richieste del pannello (08): dalla membership dell'utente autenticato, mai dal client.
 * Senza utente o senza membership restituisce null (il middleware risponde 404).
 */
final class MembershipTenantResolver implements TenantResolver
{
    public function __construct(private readonly UserTenants $userTenants) {}

    public function resolve(Request $request): ?string
    {
        $id = $request->user()?->getAuthIdentifier();

        return is_string($id) ? $this->userTenants->primaryOf($id) : null;
    }
}
