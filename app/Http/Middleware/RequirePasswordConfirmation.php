<?php

namespace App\Http\Middleware;

use App\Domain\Tenancy\Auth\PanelSession;
use App\Http\Api\ApiError;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Ri-autenticazione per le azioni sensibili (08: rimborsi, cambio account Stripe, export completo,
 * cambio email): la password deve essere stata confermata negli ultimi 15 minuti
 * (auth.password_timeout). Altrimenti 403 REAUTH_REQUIRED. Alias di rotta: "reauth".
 */
final class RequirePasswordConfirmation
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! (new PanelSession($request->session()))->passwordConfirmedWithin((int) config('auth.password_timeout'))) {
            return ApiError::response(403, 'REAUTH_REQUIRED');
        }

        return $next($request);
    }
}
