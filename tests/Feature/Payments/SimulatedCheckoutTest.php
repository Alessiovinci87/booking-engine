<?php

use App\Domain\Payments\Gateway\PaymentGateway;
use App\Domain\Reservations\Enums\ReservationStatus;
use Illuminate\Routing\RouteCollection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Route;

/*
 * Checkout simulato (3.3): con PAYMENT_GATEWAY=fake e APP_ENV local/testing la URL di checkout porta a
 * "Pagamento simulato" con tre pulsanti che producono gli stessi eventi dei webhook di Stripe. In ogni
 * altro ambiente le rotte non esistono e il gateway finto rifiuta di partire (FakePaymentGatewayTest).
 */

beforeEach(function () {
    Carbon::setTestNow('2027-06-01 10:00:00');
    $this->fx = bookableTenant('INSTANT');
    $this->reservation = startCheckout($this->fx);
    $this->session = (string) latestPayment($this->fx, $this->reservation->id)->checkout_session_id;
    $this->page = fakeGateway()->checkoutPage($this->session);
});
afterEach(fn () => Carbon::setTestNow());

it('mostra la pagina con importo e i tre pulsanti nella lingua dell\'ospite', function () {
    // L'ospite di guestDetails() ha lingua EN.
    $this->get('/_simulated-checkout/'.$this->session)
        ->assertOk()
        ->assertSee('Simulated payment')
        ->assertSee(trans('payments.simulated.pay', [], 'en'))
        ->assertSee(trans('payments.simulated.decline', [], 'en'))
        ->assertSee(trans('payments.simulated.abandon', [], 'en'))
        ->assertSee('€');
});

it('"Pagamento rifiutato" produce l\'evento di rifiuto e torna alla pagina di attesa', function () {
    $this->post('/_simulated-checkout/'.$this->session.'/decline')->assertRedirect($this->page['success_url']);

    expect(reloadReservation($this->fx, $this->reservation->id)->status)->toBe(ReservationStatus::PAYMENT_FAILED);
});

it('"Abbandona" torna alla cancel_url senza eventi', function () {
    $this->post('/_simulated-checkout/'.$this->session.'/abandon')->assertRedirect($this->page['cancel_url']);

    expect(reloadReservation($this->fx, $this->reservation->id)->status)->toBe(ReservationStatus::AWAITING_PAYMENT);
});

it('dopo l\'esito la sessione è chiusa e un secondo clic non cambia nulla', function () {
    $this->post('/_simulated-checkout/'.$this->session.'/pay')->assertRedirect();
    $this->get('/_simulated-checkout/'.$this->session)->assertOk()->assertSee(trans('payments.simulated.closed', [], 'en'));

    expect(reloadReservation($this->fx, $this->reservation->id)->status)->toBe(ReservationStatus::CONFIRMED);
});

it('risponde 404 a sessioni e azioni sconosciute', function () {
    $this->get('/_simulated-checkout/cs_fake_'.str_repeat('0', 24))->assertNotFound();
    $this->post('/_simulated-checkout/'.$this->session.'/refund')->assertNotFound();
});

it('risponde 404 se il gateway in uso non è quello finto', function () {
    app()->instance(PaymentGateway::class, Mockery::mock(PaymentGateway::class));

    $this->get('/_simulated-checkout/'.$this->session)->assertNotFound();
    $this->post('/_simulated-checkout/'.$this->session.'/pay')->assertNotFound();
});

it('non registra le rotte fuori da local/testing o con un altro gateway', function (string $env, string $gateway) {
    $originalEnv = app()->environment();
    app()->instance('env', $env);
    config(['payments.gateway' => $gateway]);
    Route::setRoutes(new RouteCollection);

    try {
        require base_path('routes/web.php');
        Route::getRoutes()->refreshNameLookups();

        expect(Route::has('simulated-checkout.show'))->toBeFalse()->and(Route::has('simulated-checkout.act'))->toBeFalse()
            ->and(Route::has('guest'))->toBeTrue();
    } finally {
        app()->instance('env', $originalEnv);
    }
})->with([
    'production' => ['production', 'fake'],
    'staging' => ['staging', 'fake'],
    'testing con stripe' => ['testing', 'stripe'],
]);
