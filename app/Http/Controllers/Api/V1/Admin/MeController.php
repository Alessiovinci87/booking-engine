<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\Models\TenantMember;
use App\Domain\Tenancy\Models\User;
use App\Http\Controllers\Controller;
use App\Http\Resources\Admin\MeResource;
use Illuminate\Http\Request;

/**
 * GET /api/v1/admin/me: utente autenticato e tenant attivo (dalla membership, mai dal client).
 */
final class MeController extends Controller
{
    public function __invoke(Request $request): MeResource
    {
        /** @var User $user */
        $user = $request->user();

        // Dentro il contesto tenant: RLS restituisce solo il tenant della richiesta.
        $tenant = Tenant::query()->firstOrFail();
        $member = TenantMember::query()->where('user_id', $user->id)->firstOrFail();

        return new MeResource($user, $tenant, $member);
    }
}
