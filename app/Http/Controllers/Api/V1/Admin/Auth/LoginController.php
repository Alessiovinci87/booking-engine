<?php

namespace App\Http\Controllers\Api\V1\Admin\Auth;

use App\Domain\Tenancy\Auth\LoginService;
use App\Domain\Tenancy\Auth\PanelSession;
use App\Http\Api\ApiError;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Auth\LoginRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

/**
 * Accesso al pannello con email e password (08). Il primo fattore apre la sessione; ogni API admin
 * richiede poi la MFA verificata (EnsureMfaVerified).
 */
final class LoginController extends Controller
{
    public function __invoke(LoginRequest $request, LoginService $login): JsonResponse
    {
        if (! $request->hasSession()) {
            return ApiError::response(401, 'UNAUTHENTICATED');
        }

        $result = $login->attempt($request->string('email')->toString(), $request->string('password')->toString(), $request->ip());

        if ($result->throttled) {
            return ApiError::response(429, 'RATE_LIMITED', [], ['Retry-After' => (string) $result->retryAfter]);
        }

        if ($result->user === null) {
            return ApiError::response(401, 'INVALID_CREDENTIALS');
        }

        Auth::guard('web')->login($result->user);
        $request->session()->regenerate();
        (new PanelSession($request->session()))->start();

        return new JsonResponse([
            'mfa_setup_required' => ! $result->user->hasMfaEnabled(),
        ]);
    }
}
