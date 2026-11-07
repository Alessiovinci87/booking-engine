<?php

namespace App\Http\Middleware;

use App\Domain\Tenancy\Auth\PanelSession;
use App\Domain\Tenancy\Models\User;
use App\Http\Api\ApiError;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Scadenza della sessione del pannello (08): 2 ore di inattività e 12 ore dal login. Una sessione
 * scaduta viene chiusa e la richiesta risponde 401 UNAUTHENTICATED. Vale anche per le sessioni iniziate
 * prima di una revoca (`users.sessions_revoked_at`, 6.10: user:reset-mfa).
 */
final class EnsurePanelSessionIsFresh
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $panel = new PanelSession($request->session());
        $user = $request->user();
        $revokedAt = $user instanceof User ? $user->sessions_revoked_at : null;

        if ($panel->isExpired(
            (int) config('security.session.idle_minutes') * 60,
            (int) config('security.session.absolute_minutes') * 60,
        ) || ($revokedAt !== null && $panel->startedNotAfter($revokedAt))) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return ApiError::response(401, 'UNAUTHENTICATED');
        }

        $panel->touch();

        return $next($request);
    }
}
