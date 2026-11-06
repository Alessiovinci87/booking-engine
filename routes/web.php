<?php

use App\Domain\Payments\Gateway\Fake\FakePaymentGateway;
use App\Http\Controllers\SimulatedCheckoutController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/app');

// SPA del pannello (solo italiano): React Router gestisce i percorsi sotto /app.
Route::view('/app/{any?}', 'panel')->where('any', '.*')->name('panel');

// SPA della pagina ospite (italiano e inglese), servita per struttura.
Route::view('/p/{tenantSlug}/{propertySlug}/{any?}', 'guest')
    ->where('any', '.*')
    ->name('guest');

// Checkout simulato (3.3): solo con PAYMENT_GATEWAY=fake e APP_ENV local o testing. In ogni altro
// ambiente le rotte non esistono (e il gateway finto rifiuta comunque di partire).
if (config('payments.gateway') === 'fake' && FakePaymentGateway::allowedIn((string) app()->environment())) {
    Route::get('/_simulated-checkout/{session}', [SimulatedCheckoutController::class, 'show'])
        ->where('session', 'cs_fake_[a-f0-9]{24}')
        ->name('simulated-checkout.show');
    Route::post('/_simulated-checkout/{session}/{action}', [SimulatedCheckoutController::class, 'act'])
        ->where(['session' => 'cs_fake_[a-f0-9]{24}', 'action' => 'pay|decline|abandon'])
        ->name('simulated-checkout.act');
}
