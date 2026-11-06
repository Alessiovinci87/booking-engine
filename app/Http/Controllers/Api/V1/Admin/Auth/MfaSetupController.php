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
use Illuminate\Http\Request;

/**
 * Configurazione obbligatoria della MFA al primo accesso (08). Possibile solo finché la MFA non è
 * attiva: con la sola password non si può sostituire il secondo fattore di un account.
 */
final class MfaSetupController extends Controller
{
    public function __construct(
        private readonly MfaService $mfa,
        private readonly AuditLogger $audit,
        private readonly UserTenants $userTenants,
    ) {}

    public function show(Request $request): JsonResponse
    {
        $user = $request->user();

        if (! $user instanceof User) {
            return ApiError::response(401, 'UNAUTHENTICATED');
        }

        if ($user->hasMfaEnabled()) {
            return ApiError::response(409, 'MFA_ALREADY_ENABLED');
        }

        $secret = $this->mfa->beginSetup($user);
        $url = $this->mfa->otpauthUrl($user, $secret);

        return new JsonResponse([
            'qr_svg' => $this->mfa->qrSvg($url),
            'secret' => $secret,
        ], 200, ['Cache-Control' => 'no-store']);
    }

    public function store(MfaCodeRequest $request, RecoveryCodes $recoveryCodes, LoginThrottle $throttle): JsonResponse
    {
        $user = $request->user();

        if (! $user instanceof User) {
            return ApiError::response(401, 'UNAUTHENTICATED');
        }

        if ($user->hasMfaEnabled()) {
            return ApiError::response(409, 'MFA_ALREADY_ENABLED');
        }

        if ($throttle->tooManyMfaAttempts($user)) {
            return ApiError::response(429, 'RATE_LIMITED', [], ['Retry-After' => (string) $throttle->mfaRetryAfter($user)]);
        }

        if (! $this->mfa->confirmSetup($user, $request->string('code')->toString())) {
            $throttle->hitMfa($user);

            return ApiError::response(401, 'INVALID_MFA_CODE');
        }

        $throttle->clearMfa($user);
        $codes = $recoveryCodes->regenerate($user);

        $request->session()->regenerate();
        (new PanelSession($request->session()))->markMfaVerified();

        $this->audit->record(
            'auth.mfa.enabled',
            'user',
            $user->id,
            ['recovery_codes' => count($codes)],
            tenantId: $this->userTenants->primaryOf($user->id),
            actor: AuditActor::user($user->id),
        );

        return new JsonResponse(['recovery_codes' => $codes], 200, ['Cache-Control' => 'no-store']);
    }
}
