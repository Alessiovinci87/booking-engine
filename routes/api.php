<?php

use App\Http\Controllers\Api\V1\Admin\Auth\LoginController;
use App\Http\Controllers\Api\V1\Admin\Auth\MfaSetupController;
use App\Http\Controllers\Api\V1\Admin\Auth\MfaVerifyController;
use App\Http\Controllers\Api\V1\Admin\Auth\PasswordController;
use App\Http\Controllers\Api\V1\Admin\Auth\SessionController;
use App\Http\Controllers\Api\V1\Admin\BlockController;
use App\Http\Controllers\Api\V1\Admin\CalendarController;
use App\Http\Controllers\Api\V1\Admin\ConflictController;
use App\Http\Controllers\Api\V1\Admin\DisputeController;
use App\Http\Controllers\Api\V1\Admin\ExternalCalendarController;
use App\Http\Controllers\Api\V1\Admin\GuestPrivacyController;
use App\Http\Controllers\Api\V1\Admin\IcsFeedController;
use App\Http\Controllers\Api\V1\Admin\MeController;
use App\Http\Controllers\Api\V1\Admin\MetricsController;
use App\Http\Controllers\Api\V1\Admin\PricePreviewController;
use App\Http\Controllers\Api\V1\Admin\PropertyController;
use App\Http\Controllers\Api\V1\Admin\QuoteController;
use App\Http\Controllers\Api\V1\Admin\RatePlanController;
use App\Http\Controllers\Api\V1\Admin\RefundController;
use App\Http\Controllers\Api\V1\Admin\ReservationController;
use App\Http\Controllers\Api\V1\Admin\StripeAccountController;
use App\Http\Controllers\Api\V1\Admin\TenantOffboardingController;
use App\Http\Controllers\Api\V1\Admin\UnitController;
use App\Http\Controllers\Api\V1\Admin\UnitPhotoController;
use App\Http\Controllers\Api\V1\Public\PropertyController as PublicPropertyController;
use App\Http\Controllers\Api\V1\Public\QuoteController as PublicQuoteController;
use App\Http\Controllers\Api\V1\Public\ReservationController as PublicReservationController;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful;

// Identificativi UUID nei percorsi: un valore diverso non trova la rotta (404) e non arriva mai al database.
foreach (['property', 'unit', 'photo', 'season', 'reservation', 'block', 'refund', 'quote', 'guest', 'export', 'feed'] as $parameter) {
    Route::pattern($parameter, '[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}');
}

/*
 * API v1 (07). Prefisso /api/v1 da bootstrap/app.php.
 *
 * Pannello: Sanctum SPA (cookie + CSRF). Le rotte di autenticazione stanno sotto /admin/auth;
 * tutte le altre rotte admin richiedono sessione valida, MFA verificata e contesto tenant.
 */
Route::prefix('admin')->middleware(EnsureFrontendRequestsAreStateful::class)->group(function (): void {
    Route::prefix('auth')->group(function (): void {
        Route::post('login', LoginController::class)->name('admin.auth.login');
        // Password dimenticata (6.11, ADR-0016): senza sessione; la reimpostazione non apre la sessione.
        Route::post('password/forgot', [PasswordController::class, 'forgot'])->name('admin.auth.password.forgot');
        Route::post('password/reset', [PasswordController::class, 'reset'])->name('admin.auth.password.reset');

        Route::middleware(['auth:sanctum', 'panel.fresh'])->group(function (): void {
            Route::get('session', [SessionController::class, 'show'])->name('admin.auth.session');
            Route::post('logout', [SessionController::class, 'destroy'])->name('admin.auth.logout');
            Route::get('mfa/setup', [MfaSetupController::class, 'show'])->name('admin.auth.mfa.setup');
            Route::post('mfa/setup', [MfaSetupController::class, 'store'])->name('admin.auth.mfa.confirm');
            Route::post('mfa/verify', MfaVerifyController::class)->name('admin.auth.mfa.verify');

            Route::post('confirm-password', [SessionController::class, 'confirmPassword'])
                ->middleware('mfa')
                ->name('admin.auth.confirm-password');
            Route::post('password', [PasswordController::class, 'change'])
                ->middleware('mfa')
                ->name('admin.auth.password.change');
        });
    });

    // L'Idempotency-Key (07, I9) precede il middleware tenant: la risposta si salva dopo il commit.
    Route::middleware(['auth:sanctum', 'panel.fresh', 'mfa', 'throttle:admin', 'idempotent', 'tenant', 'tenant.writable'])->group(function (): void {
        Route::post('properties/{property}/publish', [PropertyController::class, 'publish'])->name('admin.properties.publish');

        Route::post('reservations', [ReservationController::class, 'store'])->name('admin.reservations.store');
        Route::post('reservations/{reservation}/approve', [ReservationController::class, 'approve'])->name('admin.reservations.approve');
        Route::post('reservations/{reservation}/reject', [ReservationController::class, 'reject'])->name('admin.reservations.reject');
        Route::post('reservations/{reservation}/cancel', [ReservationController::class, 'cancel'])->name('admin.reservations.cancel');
        Route::post('reservations/{reservation}/release-dates', [ReservationController::class, 'releaseDates'])->name('admin.reservations.release-dates');
        Route::post('reservations/{reservation}/offline-payments', [ReservationController::class, 'offlinePayment'])->name('admin.reservations.offline-payments');
        Route::post('units/{unit}/blocks', [BlockController::class, 'store'])->name('admin.units.blocks.store');
        Route::post('refunds/{refund}/retry', [RefundController::class, 'retry'])->middleware('reauth')->name('admin.refunds.retry');
        Route::post('refunds/{refund}/mark-offline', [RefundController::class, 'markOffline'])->middleware('reauth')->name('admin.refunds.mark-offline');
        // Anonimizzazione dell'ospite (09, 6.3): irreversibile, con ri-autenticazione (ADR-0014).
        Route::post('guests/{guest}/erase', [GuestPrivacyController::class, 'erase'])->middleware('reauth')->name('admin.guests.erase');
        Route::post('quotes', [QuoteController::class, 'store'])->name('admin.quotes.store');
        Route::post('quotes/{quote}/send', [QuoteController::class, 'send'])->name('admin.quotes.send');
        Route::post('quotes/{quote}/revoke', [QuoteController::class, 'revoke'])->name('admin.quotes.revoke');
    });

    // Chiamate al servizio di pagamento fuori transazione (05): il servizio apre i propri passi.
    Route::middleware(['auth:sanctum', 'panel.fresh', 'mfa', 'throttle:admin', 'idempotent', 'tenant:panel,steps', 'tenant.writable'])->group(function (): void {
        Route::post('reservations/{reservation}/recovery-link', [ReservationController::class, 'recoveryLink'])->name('admin.reservations.recovery-link');
    });

    // Calendari importati (5.6): download con SafeHttpFetcher fuori transazione, il servizio apre i propri passi.
    Route::middleware(['auth:sanctum', 'panel.fresh', 'mfa', 'throttle:admin', 'tenant:panel,steps', 'tenant.writable'])->group(function (): void {
        Route::post('units/{unit}/external-calendars', [ExternalCalendarController::class, 'store'])->name('admin.units.external-calendars.store');
        Route::patch('external-calendars/{calendar}', [ExternalCalendarController::class, 'update'])->name('admin.external-calendars.update');
        Route::post('external-calendars/{calendar}/sync-now', [ExternalCalendarController::class, 'syncNow'])->name('admin.external-calendars.sync-now');
    });

    // Collegamento Stripe (4.1): letture e link di onboarding con chiamate a Stripe fuori transazione.
    Route::middleware(['auth:sanctum', 'panel.fresh', 'mfa', 'throttle:admin', 'tenant:panel,steps', 'tenant.writable'])->group(function (): void {
        Route::get('stripe/account', [StripeAccountController::class, 'show'])->name('admin.stripe.account');
        Route::post('stripe/onboarding-link', [StripeAccountController::class, 'onboardingLink'])->middleware('reauth')->name('admin.stripe.onboarding-link');
    });

    Route::middleware(['auth:sanctum', 'panel.fresh', 'mfa', 'throttle:admin', 'tenant', 'tenant.writable'])->group(function (): void {
        Route::get('me', MeController::class)->name('admin.me');
        Route::get('properties', [PropertyController::class, 'index'])->name('admin.properties.index');
        Route::post('properties', [PropertyController::class, 'store'])->name('admin.properties.store');
        Route::get('properties/{property}', [PropertyController::class, 'show'])->name('admin.properties.show');
        Route::patch('properties/{property}', [PropertyController::class, 'update'])->name('admin.properties.update');

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
        Route::post('reservations/{reservation}/modify-preview', [ReservationController::class, 'previewModify'])->name('admin.reservations.modify-preview');

        Route::get('calendar', CalendarController::class)->name('admin.calendar');
        Route::delete('units/{unit}/blocks/{block}', [BlockController::class, 'destroy'])->name('admin.units.blocks.destroy');
        Route::get('reservations', [ReservationController::class, 'index'])->name('admin.reservations.index');
        Route::get('reservations/{reservation}', [ReservationController::class, 'show'])->name('admin.reservations.show');
        Route::patch('reservations/{reservation}', [ReservationController::class, 'update'])->name('admin.reservations.update');
        Route::get('refunds', [RefundController::class, 'index'])->name('admin.refunds.index');
        Route::get('disputes', [DisputeController::class, 'index'])->name('admin.disputes.index');

        Route::get('units/{unit}/external-calendars', [ExternalCalendarController::class, 'index'])->name('admin.units.external-calendars.index');
        Route::delete('external-calendars/{calendar}', [ExternalCalendarController::class, 'destroy'])->name('admin.external-calendars.destroy');
        Route::get('external-calendars/{calendar}/runs', [ExternalCalendarController::class, 'runs'])->name('admin.external-calendars.runs');
        Route::post('external-events/{event}/remove', [ExternalCalendarController::class, 'removeEvent'])->name('admin.external-events.remove');
        Route::get('units/{unit}/calendar-checklist', [ExternalCalendarController::class, 'checklist'])->name('admin.units.calendar-checklist');
        Route::post('units/{unit}/calendar-checklist/confirm', [ExternalCalendarController::class, 'confirmChecklist'])->name('admin.units.calendar-checklist.confirm');

        Route::get('units/{unit}/ics-feeds', [IcsFeedController::class, 'index'])->name('admin.units.ics-feeds.index');
        Route::post('units/{unit}/ics-feeds', [IcsFeedController::class, 'store'])->name('admin.units.ics-feeds.store');
        Route::post('ics-feeds/{feed}/rotate', [IcsFeedController::class, 'rotate'])->name('admin.ics-feeds.rotate');

        Route::get('conflicts', [ConflictController::class, 'index'])->name('admin.conflicts.index');
        Route::post('conflicts/{conflict}/investigate', [ConflictController::class, 'investigate'])->name('admin.conflicts.investigate');
        Route::post('conflicts/{conflict}/resolve', [ConflictController::class, 'resolve'])->name('admin.conflicts.resolve');
        Route::post('conflicts/{conflict}/ignore', [ConflictController::class, 'ignore'])->name('admin.conflicts.ignore');
        Route::post('external-events/{event}/not-echo', [ConflictController::class, 'notAnEcho'])->name('admin.external-events.not-echo');

        Route::get('quotes', [QuoteController::class, 'index'])->name('admin.quotes.index');

        // Metriche ed export CSV (6.2).
        Route::get('metrics', [MetricsController::class, 'metrics'])->name('admin.metrics');
        // Export con dati personali (08 "export completo", 6.7): password confermata negli ultimi 15 minuti.
        Route::get('guests/{guest}/export', [GuestPrivacyController::class, 'export'])->middleware('reauth')->name('admin.guests.export');
        Route::get('exports/reservations.csv', [MetricsController::class, 'reservationsCsv'])->middleware('reauth')->name('admin.exports.reservations');

        // Uscita del cliente (7.1; 06, 09, ADR-0018).
        Route::get('tenant/offboarding', [TenantOffboardingController::class, 'show'])->name('admin.tenant.offboarding');
        Route::post('tenant/offboarding/start', [TenantOffboardingController::class, 'start'])->middleware('reauth')->name('admin.tenant.offboarding.start');
        Route::post('tenant/offboarding/channels/{feed}/confirm-removed', [TenantOffboardingController::class, 'confirmRemoved'])->middleware('reauth')->name('admin.tenant.offboarding.confirm-removed');
        Route::post('tenant/export', [TenantOffboardingController::class, 'export'])->middleware('reauth')->name('admin.tenant.export');
        Route::get('tenant/exports', [TenantOffboardingController::class, 'exports'])->name('admin.tenant.exports');
        Route::get('tenant/exports/{export}/download', [TenantOffboardingController::class, 'download'])->middleware(['signed:relative', 'reauth'])->name('admin.tenant.exports.download');
        Route::get('quotes/{quote}', [QuoteController::class, 'show'])->name('admin.quotes.show');
    });
});

// Preventivo dalla pagina ospite /q/{token} (07, 5.7): tenant dal token; accettazione con
// Idempotency-Key, limite per IP ed email e passi fuori transazione (sessione di pagamento, 05). Registrate prima di public/{t}/{p}:
// un token di sole minuscole e cifre corrisponderebbe anche a quelle rotte.
Route::prefix('public/quotes/{token}')->where(['token' => '[A-Za-z0-9_-]{43}'])->middleware(['guest.locale'])->group(function (): void {
    Route::get('/', [PublicQuoteController::class, 'show'])
        ->middleware(['throttle:public-read', 'tenant:quote'])
        ->name('public.quotes.show');
    Route::post('accept', [PublicQuoteController::class, 'accept'])
        ->middleware(['throttle:public-booking', 'idempotent', 'tenant:quote,steps'])
        ->name('public.quotes.accept');
});

/*
 * API pubblica (07): nessuna autenticazione, tenant dallo slug nel percorso, lingua da locale o
 * Accept-Language, 120 richieste al minuto per IP.
 */
Route::prefix('public/{tenantSlug}/{propertySlug}')
    ->where(['tenantSlug' => '[a-z0-9-]{1,63}', 'propertySlug' => '[a-z0-9-]{1,100}'])
    ->middleware(['guest.locale'])
    ->group(function (): void {
        Route::middleware(['throttle:public-read', 'tenant:public', 'public.origin'])->group(function (): void {
            Route::get('/', [PublicPropertyController::class, 'show'])->name('public.property.show');
            Route::get('availability', [PublicPropertyController::class, 'availability'])->name('public.property.availability');
            Route::post('quote-price', [PublicPropertyController::class, 'quotePrice'])->name('public.property.quote-price');
        });

        // Avvio della prenotazione (07): Idempotency-Key, limite per IP ed email, passi fuori
        // transazione (la sessione di pagamento si apre fuori, 05).
        Route::post('reservations', [PublicReservationController::class, 'store'])
            ->middleware(['throttle:public-booking', 'idempotent', 'tenant:public,steps', 'public.origin'])
            ->name('public.reservations.store');
    });

// Stato della prenotazione per la pagina di ritorno (07): tenant da codice e token.
Route::get('public/reservations/{code}', [PublicReservationController::class, 'show'])
    ->where('code', '[A-Z0-9]{8}')
    ->middleware(['throttle:public-read', 'guest.locale', 'tenant:reservation'])
    ->name('public.reservations.show');
