<?php

namespace App\Http\Controllers\Api\V1\Admin\Auth;

use App\Domain\Tenancy\Audit\AuditActor;
use App\Domain\Tenancy\Audit\AuditLogger;
use App\Domain\Tenancy\Auth\LoginThrottle;
use App\Domain\Tenancy\Auth\PanelSession;
use App\Domain\Tenancy\Auth\PasswordManagement;
use App\Domain\Tenancy\Auth\PasswordPolicy;
use App\Domain\Tenancy\Auth\UserTenants;
use App\Domain\Tenancy\Models\User;
use App\Http\Api\ApiError;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Gestione della password del gestore (6.11, ADR-0016): password dimenticata, reimpostazione con il
 * link monouso e cambio password dal pannello.
 */
final class PasswordController extends Controller
{
    public function __construct(private readonly PasswordManagement $passwords) {}

    /** Risposta sempre identica (202), che l'email esista o no; 429 oltre il limite per email o per IP. */
    public function forgot(Request $request): JsonResponse
    {
        $request->validate(['email' => ['required', 'string', 'email', 'max:254']]);

        $retryAfter = $this->passwords->requestReset($request->string('email')->toString(), $request->ip());

        if ($retryAfter !== null) {
            return ApiError::response(429, 'RATE_LIMITED', [], ['Retry-After' => (string) $retryAfter]);
        }

        return new JsonResponse(['status' => 'accepted'], 202);
    }

    /** Nuova password con il token del link. Non apre la sessione: dopo serve il login con la MFA. */
    public function reset(Request $request): Response|JsonResponse
    {
        $request->validate([
            'token' => ['required', 'string', 'max:200'],
            'password' => [...PasswordPolicy::rules(), 'confirmed'],
        ]);

        if (! $this->passwords->reset($request->string('token')->toString(), $request->string('password')->toString())) {
            return ApiError::response(422, 'PASSWORD_RESET_INVALID');
        }

        return response()->noContent();
    }

    /**
     * Cambio password: password attuale (stesso limite di tentativi del login) e MFA verificata nella
     * sessione (middleware `mfa`). Le altre sessioni si chiudono; questa resta aperta.
     */
    public function change(Request $request, LoginThrottle $throttle, AuditLogger $audit, UserTenants $userTenants): Response|JsonResponse
    {
        $request->validate([
            'current_password' => ['required', 'string', 'max:200'],
            'password' => [...PasswordPolicy::rules(), 'confirmed', 'different:current_password'],
        ]);
        $user = $request->user();

        if (! $user instanceof User) {
            return ApiError::response(401, 'UNAUTHENTICATED');
        }

        if ($throttle->tooManyLoginAttempts($user->email, $request->ip())) {
            return ApiError::response(429, 'RATE_LIMITED', [], ['Retry-After' => (string) $throttle->loginRetryAfter($user->email, $request->ip())]);
        }

        if (! $this->passwords->checkCurrentPassword($user, $request->string('current_password')->toString())) {
            $throttle->hitLogin($user->email, $request->ip());
            $audit->record('auth.password_change.failed', 'user', $user->id, tenantId: $userTenants->primaryOf($user->id), actor: AuditActor::user($user->id));

            return ApiError::response(401, 'INVALID_CREDENTIALS');
        }

        $revokedAt = $this->passwords->change($user, $request->string('password')->toString());

        $request->session()->regenerate();
        (new PanelSession($request->session()))->acknowledgeRevocation($revokedAt);

        return response()->noContent();
    }
}
