<?php

namespace App\Domain\Tenancy;

use App\Domain\Reservations\StateMachines\ReservationStateMachine;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Tenant di GET /public/reservations/{code}?token= (07): dalla funzione SECURITY DEFINER
 * app_tenant_by_reservation_access, che risponde solo se codice e hash del token corrispondono.
 * Codice o token mancanti, malformati o sbagliati → null, quindi 404 (mai 403, 08).
 */
final class ReservationAccessTenantResolver implements TenantResolver
{
    public function resolve(Request $request): ?string
    {
        $code = $request->route('code');
        $token = $request->query('token');

        if (! is_string($code) || preg_match('/^[A-Z0-9]{8}$/', $code) !== 1 || ! is_string($token) || strlen($token) < 32 || strlen($token) > 128) {
            return null;
        }

        $id = DB::selectOne('SELECT app_tenant_by_reservation_access(?, ?) AS id', [$code, ReservationStateMachine::hashToken($token)])?->id;

        return is_string($id) ? $id : null;
    }
}
