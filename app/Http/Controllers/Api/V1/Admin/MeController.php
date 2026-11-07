<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domain\Tenancy\Http\EnforceSupportAccess;
use App\Domain\Tenancy\Models\SupportAccessGrant;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\Models\TenantMember;
use App\Domain\Tenancy\Models\User;
use App\Http\Controllers\Controller;
use App\Http\Resources\Admin\MeResource;
use Illuminate\Http\Request;

/**
 * GET /api/v1/admin/me: utente autenticato e tenant attivo (dalla membership, mai dal client).
 * Per un operatore di piattaforma (7.5) il tenant è quello dell'autorizzazione di supporto attiva e la
 * risposta riporta l'autorizzazione al posto del ruolo: il pannello mostra il banner "Accesso di supporto".
 */
final class MeController extends Controller
{
    public function __invoke(Request $request): MeResource
    {
        /** @var User $user */
        $user = $request->user();

        // Dentro il contesto tenant: RLS restituisce solo il tenant della richiesta.
        $tenant = Tenant::query()->firstOrFail();
        $grant = $request->attributes->get(EnforceSupportAccess::GRANT_ATTRIBUTE);

        if (is_array($grant) && is_string($grant['grant_id'] ?? null)) {
            return new MeResource($user, $tenant, null, SupportAccessGrant::query()->findOrFail($grant['grant_id']));
        }

        $member = TenantMember::query()->where('user_id', $user->id)->firstOrFail();

        return new MeResource($user, $tenant, $member);
    }
}
