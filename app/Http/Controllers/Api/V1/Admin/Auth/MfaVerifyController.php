<?php

namespace App\Http\Controllers\Api\V1\Admin\Auth;

use App\Domain\Tenancy\Audit\AuditActor;
use App\Domain\Tenancy\Audit\AuditLogger;
use App\Domain\Tenancy\Auth\LoginThrottle;
use App\Domain\Tenancy\Auth\MfaService;
use App\Domain\Tenancy\Auth\PanelSession;
use App\Domain\Tenancy\Auth\RecoveryCodes;
use App\Domain\Tenancy\Auth\UserTenants;
use App\Domain\Tenancy\Models\User;
use App\Http\Api\ApiError;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Auth\MfaCodeRequest;
use Illuminate\Http\JsonResponse;

/**
 * Secondo fattore a ogni accesso (08): codice TOTP oppure codice di recupero monouso. Stesso limite
 * di tentativi del login.
 */
final class MfaVerifyController extends Controller
{
    public function __invoke(
        MfaCodeRequest $request,
        MfaService $mfa,
        RecoveryCodes $recoveryCodes,
        LoginThrottle $throttle,
        AuditLogger $audit,
        UserTenants $userTenants,
    ): JsonResponse {
        $user = $request->user();

        if (! $user instanceof User) {
            return ApiError::response(401, 'UNAUTHENTICATED');
        }

        if (! $user->hasMfaEnabled()) {
            return ApiError::response(401, 'MFA_SETUP_REQUIRED');
        }

        $tenantId = $userTenants->primaryOf($user->id);

        if ($throttle->tooManyMfaAttempts($user)) {
            return ApiError::response(429, 'RATE_LIMITED', [], ['Retry-After' => (string) $throttle->mfaRetryAfter($user)]);
        }

        $recovery = $request->filled('recovery_code');
        $valid = $recovery
            ? $recoveryCodes->consume($user, $request->string('recovery_code')->toString())
            : $mfa->verify($user, $request->string('code')->toString());

        if (! $valid) {
            $throttle->hitMfa($user);
            $audit->record('auth.mfa.failed', 'user', $user->id, ['method' => $recovery ? 'recovery_code' : 'totp'], $tenantId, AuditActor::user($user->id));

            return ApiError::response(401, 'INVALID_MFA_CODE');
        }

        $throttle->clearMfa($user);
        $request->session()->regenerate();
        (new PanelSession($request->session()))->markMfaVerified();

        $changes = ['method' => $recovery ? 'recovery_code' : 'totp'];

        if ($recovery) {
            $changes['recovery_codes_remaining'] = $recoveryCodes->remaining($user);
        }

        $audit->record('auth.mfa.verified', 'user', $user->id, $changes, $tenantId, AuditActor::user($user->id));

        return new JsonResponse(['recovery_codes_remaining' => $recoveryCodes->remaining($user)]);
    }
}
