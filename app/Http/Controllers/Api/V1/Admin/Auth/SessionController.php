<?php

namespace App\Http\Controllers\Api\V1\Admin\Auth;

use App\Domain\Tenancy\Audit\AuditActor;
use App\Domain\Tenancy\Audit\AuditLogger;
use App\Domain\Tenancy\Auth\LoginThrottle;
use App\Domain\Tenancy\Auth\PanelSession;
use App\Domain\Tenancy\Auth\UserTenants;
use App\Domain\Tenancy\Models\User;
use App\Http\Api\ApiError;
use App\Http\Controllers\Controller;
use Illuminate\Contracts\Hashing\Hasher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;

/**
 * Stato della sessione per la SPA, conferma della password (ri-autenticazione, 08) e logout.
 */
final class SessionController extends Controller
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly UserTenants $userTenants,
    ) {}

    public function show(Request $request): JsonResponse
    {
        $user = $request->user();

        if (! $user instanceof User) {
            return ApiError::response(401, 'UNAUTHENTICATED');
        }

        return new JsonResponse([
            'mfa_enabled' => $user->hasMfaEnabled(),
            'mfa_verified' => (new PanelSession($request->session()))->isMfaVerified(),
        ]);
    }

    public function confirmPassword(Request $request, Hasher $hasher, LoginThrottle $throttle): Response|JsonResponse
    {
        $request->validate(['password' => ['required', 'string', 'max:200']]);
        $user = $request->user();

        if (! $user instanceof User) {
            return ApiError::response(401, 'UNAUTHENTICATED');
        }

        if ($throttle->tooManyLoginAttempts($user->email, $request->ip())) {
            return ApiError::response(429, 'RATE_LIMITED', [], ['Retry-After' => (string) $throttle->loginRetryAfter($user->email, $request->ip())]);
        }

        if (! $hasher->check($request->string('password')->toString(), $user->password_hash)) {
            $throttle->hitLogin($user->email, $request->ip());
            $this->audit->record('auth.password_confirmation.failed', 'user', $user->id, tenantId: $this->userTenants->primaryOf($user->id), actor: AuditActor::user($user->id));

            return ApiError::response(401, 'INVALID_CREDENTIALS');
        }

        (new PanelSession($request->session()))->markPasswordConfirmed();
        $this->audit->record('auth.password_confirmed', 'user', $user->id, tenantId: $this->userTenants->primaryOf($user->id), actor: AuditActor::user($user->id));

        return response()->noContent();
    }

    public function destroy(Request $request): Response
    {
        $user = $request->user();

        if ($user instanceof User) {
            $this->audit->record('auth.logout', 'user', $user->id, tenantId: $this->userTenants->primaryOf($user->id), actor: AuditActor::user($user->id));
        }

        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->noContent();
    }
}
