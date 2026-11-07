<?php

use App\Domain\Reservations\LegalAcceptance;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/*
 * 4.9 (05 "Checkout", 08): con Stripe la pagina ospite apre il checkout a pagina intera sull'URL della
 * sessione; la pagina di ritorno non conferma nulla da sola (I6): lo stato resta AWAITING_PAYMENT finché
 * non arriva il webhook verificato, poi diventa CONFIRMED. URL di ritorno e metadata senza token né dati
 * personali. Stripe simulato.
 */

beforeEach(function () {
    Carbon::setTestNow('2027-06-01 10:00:00');
    $this->sim = stripeSimulator();
    $this->fx = bookableTenant('INSTANT');
});
afterEach(function () {
    resetStripeHttp();
    Carbon::setTestNow();
});

function stripeBooking(TestCase $test, array $fx): TestResponse
{
    return $test->withHeaders(['Idempotency-Key' => uuid7(), 'Accept-Language' => 'en'])->postJson("/api/v1/public/{$fx['slug']}/{$fx['property_slug']}/reservations", [
        'unit_id' => $fx['unit'], 'check_in' => '2027-07-10', 'check_out' => '2027-07-14', 'adults' => 2, 'children' => 0,
        'guest' => ['first_name' => 'Ada', 'last_name' => 'Lovelace', 'email' => 'ada@example.test', 'phone' => '+44 20 7946 0000', 'country_code' => 'GB'],
        'locale' => 'en',
        'accepted_terms_version' => LegalAcceptance::version('https://example.test/termini'),
        'accepted_privacy_version' => LegalAcceptance::version('https://example.test/privacy'),
        'expected_total_cents' => 54000,
    ]);
}

it('checkout sull\'URL di Stripe; URL di ritorno e metadata senza token né dati personali', function () {
    $response = stripeBooking($this, $this->fx)->assertCreated()->assertJsonPath('status', 'AWAITING_PAYMENT');
    $session = $this->sim->lastSession();
    $token = (string) $response->json('access_token');

    expect($response->json('checkout_url'))->toBe($session['url'])
        ->and($session['success_url'])->toContain('/prenotazione/'.$response->json('reservation_code'))->not->toContain($token)
        ->and($session['cancel_url'])->toContain('cancelled=1')->not->toContain($token)
        ->and($session['locale'])->toBe('en')
        ->and(json_encode($session['metadata']))->not->toContain('ada@example.test')->not->toContain('Lovelace')->not->toContain($token);
});

it('il ritorno dal checkout non conferma: AWAITING_PAYMENT fino al webhook, poi CONFIRMED', function () {
    $response = stripeBooking($this, $this->fx)->assertCreated();
    $code = (string) $response->json('reservation_code');
    $status = fn () => $this->getJson("/api/v1/public/reservations/{$code}?token=".$response->json('access_token'));

    // Pagato su Stripe e ospite tornato sulla success_url, ma il webhook non è ancora arrivato.
    $this->sim->pay($this->sim->lastSession()['id']);
    $status()->assertOk()->assertJsonPath('status', 'AWAITING_PAYMENT');

    postStripeWebhook($this, $this->sim->event('checkout.session.completed', $this->sim->lastSessionView($this->sim->lastSession()['id']), $this->fx['account']))->assertOk();

    $status()->assertOk()->assertJsonPath('status', 'CONFIRMED');
});

it('Stripe non disponibile: 503 PAYMENT_PROVIDER_UNAVAILABLE con la chiave di traduzione della pagina ospite', function () {
    $this->sim->http->fail('post', '/v1/checkout/sessions', 500, 'api_error', times: 10);

    stripeBooking($this, $this->fx)->assertStatus(503)->assertJsonPath('error.code', 'PAYMENT_PROVIDER_UNAVAILABLE');

    foreach (['it', 'en'] as $locale) {
        $messages = json_decode((string) file_get_contents(base_path("resources/js/guest/locales/{$locale}.json")), true);
        expect($messages['errors']['PAYMENT_PROVIDER_UNAVAILABLE'] ?? null)->toBeString();
    }
});
