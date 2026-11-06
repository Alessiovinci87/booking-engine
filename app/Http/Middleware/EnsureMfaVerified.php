<?php

namespace App\Http\Middleware;

use App\Domain\Tenancy\Auth\PanelSession;
use App\Domain\Tenancy\Models\User;
use App\Http\Api\ApiError;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Nessuna API del pannello senza MFA verificata nella sessione (07, 08). Se l'utente non ha ancora
 * configurato la MFA risponde MFA_SETUP_REQUIRED, altrimenti MFA_REQUIRED (entrambi 401).
 */
final class EnsureMfaVerified
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof User) {
            return ApiError::response(401, 'UNAUTHENTICATED');
        }

        if (! $user->hasMfaEnabled()) {
            return ApiError::response(401, 'MFA_SETUP_REQUIRED');
        }

        if (! (new PanelSession($request->session()))->isMfaVerified()) {
            return ApiError::response(401, 'MFA_REQUIRED');
        }

        return $next($request);
    }
}
