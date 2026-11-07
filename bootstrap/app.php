<?php

use App\Domain\Tenancy\Exceptions\BusinessRuleViolation;
use App\Domain\Tenancy\Http\EnforceSupportAccess;
use App\Domain\Tenancy\Http\EnsureTenantAcceptsChanges;
use App\Domain\Tenancy\Http\WithTenantContext;
use App\Http\Api\ApiError;
use App\Http\Middleware\CheckPublicOrigin;
use App\Http\Middleware\EnforceIdempotencyKey;
use App\Http\Middleware\EnsureMfaVerified;
use App\Http\Middleware\EnsurePanelSessionIsFresh;
use App\Http\Middleware\RequirePasswordConfirmation;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\SetGuestLocale;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        apiPrefix: 'api/v1',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Sanctum SPA: sessione e CSRF solo sulle rotte /admin (routes/api.php). L'API pubblica non usa
        // cookie di sessione né CSRF (07, ADR-0010): niente statefulApi() sull'intero gruppo api.

        $middleware->alias([
            // Contesto tenant per le richieste admin e pubbliche (08): Route::middleware('tenant').
            'tenant' => WithTenantContext::class,
            // Pannello in sola lettura durante l'uscita del cliente (7.1).
            'tenant.writable' => EnsureTenantAcceptsChanges::class,
            'support' => EnforceSupportAccess::class,
            'panel.fresh' => EnsurePanelSessionIsFresh::class,
            'mfa' => EnsureMfaVerified::class,
            'reauth' => RequirePasswordConfirmation::class,
            'guest.locale' => SetGuestLocale::class,
            // Idempotency-Key obbligatoria (07, I9): sempre prima di `tenant`.
            'idempotent' => EnforceIdempotencyKey::class,
            // Header Origin sull'API pubblica (07, ADR-0010): sempre dopo `tenant`.
            'public.origin' => CheckPublicOrigin::class,
            // CSP e header di sicurezza (08): security:page, security:embed, security:api.
            'security' => SecurityHeaders::class,
        ]);

        $middleware->api(append: [SecurityHeaders::class.':api']);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Errori dell'API nel formato di 07, senza dettagli interni.
        $isApi = fn (Request $request): bool => $request->is('api/*') || $request->is('ical/*');

        // Regole di dominio non rispettate: risposte previste, non errori da registrare.
        $exceptions->dontReport(BusinessRuleViolation::class);

        $exceptions->render(function (BusinessRuleViolation $e, Request $request) use ($isApi) {
            return $isApi($request) ? ApiError::response($e->httpStatus, $e->errorCode, $e->details) : null;
        });

        $exceptions->render(function (ValidationException $e, Request $request) use ($isApi) {
            return $isApi($request) ? ApiError::response(400, 'VALIDATION_FAILED', ['fields' => $e->errors()]) : null;
        });

        $exceptions->render(function (AuthenticationException $e, Request $request) use ($isApi) {
            return $isApi($request) ? ApiError::response(401, 'UNAUTHENTICATED') : null;
        });

        $exceptions->render(function (ThrottleRequestsException $e, Request $request) use ($isApi) {
            if (! $isApi($request)) {
                return null;
            }

            $retryAfter = $e->getHeaders()['Retry-After'] ?? null;

            return ApiError::response(429, 'RATE_LIMITED', [], $retryAfter === null ? [] : ['Retry-After' => (string) $retryAfter]);
        });

        $exceptions->render(function (ModelNotFoundException|NotFoundHttpException $e, Request $request) use ($isApi) {
            return $isApi($request) ? ApiError::response(404, 'NOT_FOUND') : null;
        });

        $exceptions->render(function (HttpExceptionInterface $e, Request $request) use ($isApi) {
            if (! $isApi($request)) {
                return null;
            }

            // Laravel converte TokenMismatchException in HttpException 419 prima dei callback.
            return match ($e->getStatusCode()) {
                403 => ApiError::response(403, 'FORBIDDEN'),
                404 => ApiError::response(404, 'NOT_FOUND'),
                419 => ApiError::response(419, 'CSRF_TOKEN_EXPIRED'),
                default => null,
            };
        });
    })->create();
