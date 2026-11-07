<?php

use App\Domain\Payments\Gateway\Fake\FakePaymentGateway;
use App\Http\Controllers\GuestPageController;
use App\Http\Controllers\IcalFeedController;
use App\Http\Controllers\SimulatedCheckoutController;
use App\Http\Controllers\StripeWebhookController;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Route;
use Illuminate\View\Middleware\ShareErrorsFromSession;

Route::redirect('/', '/app');

// SPA del pannello (solo italiano): React Router gestisce i percorsi sotto /app.
Route::view('/app/{any?}', 'panel')->where('any', '.*')->middleware('security:page')->name('panel');

// SPA della pagina ospite (italiano e inglese), servita per struttura, e vista embed (3.7, ADR-0009).
// Senza sessione: nessun cookie all'ospite, né nella pagina né nell'iframe (08).
$withoutSession = [EncryptCookies::class, AddQueuedCookiesToResponse::class, StartSession::class, ShareErrorsFromSession::class, PreventRequestForgery::class];

Route::get('/p/{tenantSlug}/{propertySlug}/{any?}', [GuestPageController::class, 'page'])
    ->where(['tenantSlug' => '[a-z0-9-]{1,63}', 'propertySlug' => '[a-z0-9-]{1,100}', 'any' => '.*'])
    ->withoutMiddleware($withoutSession)
    ->middleware('security:page')
    ->name('guest');

Route::get('/e/{tenantSlug}/{propertySlug}/{any?}', [GuestPageController::class, 'embed'])
    ->where(['tenantSlug' => '[a-z0-9-]{1,63}', 'propertySlug' => '[a-z0-9-]{1,100}', 'any' => '.*'])
    ->withoutMiddleware($withoutSession)
    ->middleware('security:embed')
    ->name('guest.embed');

// Webhook di Stripe per gli eventi degli account connessi (05, 4.3): firma verificata, niente
// sessione, cookie o CSRF.
Route::post('/webhooks/stripe', StripeWebhookController::class)
    ->withoutMiddleware($withoutSession)
    ->middleware('security:api')
    ->name('webhooks.stripe');

// Feed iCal esportati (06, 5.5): token nel percorso, niente sessione, cookie o CSRF; 60 richieste
// all'ora per token.
Route::get('/ical/{token}.ics', IcalFeedController::class)
    ->where('token', '[A-Za-z0-9_-]{43}')
    ->withoutMiddleware($withoutSession)
    ->middleware(['security:api', 'throttle:ical'])
    ->name('ical.feed');

// Checkout simulato (3.3): solo con PAYMENT_GATEWAY=fake e APP_ENV local o testing. In ogni altro
// ambiente le rotte non esistono (e il gateway finto rifiuta comunque di partire).
if (config('payments.gateway') === 'fake' && FakePaymentGateway::allowedIn((string) app()->environment())) {
    Route::get('/_simulated-checkout/{session}', [SimulatedCheckoutController::class, 'show'])
        ->middleware('security:page')
        ->where('session', 'cs_fake_[a-f0-9]{24}')
        ->name('simulated-checkout.show');
    Route::post('/_simulated-checkout/{session}/{action}', [SimulatedCheckoutController::class, 'act'])
        ->middleware('security:page')
        ->where(['session' => 'cs_fake_[a-f0-9]{24}', 'action' => 'pay|decline|abandon'])
        ->name('simulated-checkout.act');
}
