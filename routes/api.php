<?php

use App\Http\Controllers\Api\V1\Admin\Auth\LoginController;
use App\Http\Controllers\Api\V1\Admin\Auth\MfaSetupController;
use App\Http\Controllers\Api\V1\Admin\Auth\MfaVerifyController;
use App\Http\Controllers\Api\V1\Admin\Auth\SessionController;
use App\Http\Controllers\Api\V1\Admin\MeController;
use App\Http\Controllers\Api\V1\Admin\PropertyController;
use Illuminate\Support\Facades\Route;

/*
 * API v1 (07). Prefisso /api/v1 da bootstrap/app.php.
 *
 * Pannello: Sanctum SPA (cookie + CSRF). Le rotte di autenticazione stanno sotto /admin/auth;
 * tutte le altre rotte admin richiedono sessione valida, MFA verificata e contesto tenant.
 */
Route::prefix('admin')->group(function (): void {
    Route::prefix('auth')->group(function (): void {
        Route::post('login', LoginController::class)->name('admin.auth.login');

        Route::middleware(['auth:sanctum', 'panel.fresh'])->group(function (): void {
            Route::get('session', [SessionController::class, 'show'])->name('admin.auth.session');
            Route::post('logout', [SessionController::class, 'destroy'])->name('admin.auth.logout');
            Route::get('mfa/setup', [MfaSetupController::class, 'show'])->name('admin.auth.mfa.setup');
            Route::post('mfa/setup', [MfaSetupController::class, 'store'])->name('admin.auth.mfa.confirm');
            Route::post('mfa/verify', MfaVerifyController::class)->name('admin.auth.mfa.verify');

            Route::post('confirm-password', [SessionController::class, 'confirmPassword'])
                ->middleware('mfa')
                ->name('admin.auth.confirm-password');
        });
    });

    Route::middleware(['auth:sanctum', 'panel.fresh', 'mfa', 'throttle:admin', 'tenant'])->group(function (): void {
        Route::get('me', MeController::class)->name('admin.me');
        Route::get('properties/{property}', [PropertyController::class, 'show'])->name('admin.properties.show');
    });
});
