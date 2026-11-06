<?php

use App\Http\Controllers\Api\V1\Admin\Auth\LoginController;
use App\Http\Controllers\Api\V1\Admin\Auth\MfaSetupController;
use App\Http\Controllers\Api\V1\Admin\Auth\MfaVerifyController;
use App\Http\Controllers\Api\V1\Admin\Auth\SessionController;
use App\Http\Controllers\Api\V1\Admin\MeController;
use App\Http\Controllers\Api\V1\Admin\PricePreviewController;
use App\Http\Controllers\Api\V1\Admin\PropertyController;
use App\Http\Controllers\Api\V1\Admin\RatePlanController;
use App\Http\Controllers\Api\V1\Admin\UnitController;
use App\Http\Controllers\Api\V1\Admin\UnitPhotoController;
use Illuminate\Support\Facades\Route;

// Identificativi UUID nei percorsi: un valore diverso non trova la rotta (404) e non arriva mai al database.
foreach (['property', 'unit', 'photo', 'season'] as $parameter) {
    Route::pattern($parameter, '[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}');
}

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
        Route::get('properties', [PropertyController::class, 'index'])->name('admin.properties.index');
        Route::post('properties', [PropertyController::class, 'store'])->name('admin.properties.store');
        Route::get('properties/{property}', [PropertyController::class, 'show'])->name('admin.properties.show');
        Route::patch('properties/{property}', [PropertyController::class, 'update'])->name('admin.properties.update');
        Route::post('properties/{property}/publish', [PropertyController::class, 'publish'])->name('admin.properties.publish');

        Route::get('properties/{property}/units', [UnitController::class, 'index'])->name('admin.units.index');
        Route::post('properties/{property}/units', [UnitController::class, 'store'])->name('admin.units.store');
        Route::get('units/{unit}', [UnitController::class, 'show'])->name('admin.units.show');
        Route::patch('units/{unit}', [UnitController::class, 'update'])->name('admin.units.update');
        Route::patch('units/{unit}/sales', [UnitController::class, 'sales'])->name('admin.units.sales');

        Route::get('units/{unit}/photos', [UnitPhotoController::class, 'index'])->name('admin.units.photos.index');
        Route::post('units/{unit}/photos', [UnitPhotoController::class, 'store'])->name('admin.units.photos.store');
        Route::put('units/{unit}/photos/order', [UnitPhotoController::class, 'reorder'])->name('admin.units.photos.order');
        Route::delete('units/{unit}/photos/{photo}', [UnitPhotoController::class, 'destroy'])->name('admin.units.photos.destroy');

        Route::get('units/{unit}/rate-plan', [RatePlanController::class, 'show'])->name('admin.units.rate-plan.show');
        Route::patch('units/{unit}/rate-plan', [RatePlanController::class, 'update'])->name('admin.units.rate-plan.update');
        Route::get('units/{unit}/seasons', [RatePlanController::class, 'seasons'])->name('admin.units.seasons.index');
        Route::post('units/{unit}/seasons', [RatePlanController::class, 'storeSeason'])->name('admin.units.seasons.store');
        Route::patch('units/{unit}/seasons/{season}', [RatePlanController::class, 'updateSeason'])->name('admin.units.seasons.update');
        Route::delete('units/{unit}/seasons/{season}', [RatePlanController::class, 'destroySeason'])->name('admin.units.seasons.destroy');
        Route::post('units/{unit}/price-preview', PricePreviewController::class)->name('admin.units.price-preview');
    });
});
