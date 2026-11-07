<?php

use App\Domain\Payments\Gateway\Fake\FakeCustomerAction;
use App\Domain\Payments\Gateway\Fake\FakeFailure;
use App\Domain\Payments\Gateway\Fake\FakeOperation;
use App\Domain\Reservations\LegalAcceptance;
use App\Domain\Reservations\Models\Reservation;
use App\Domain\Tenancy\Models\AuditLog;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/*
 * 3.7: POST /public/{t}/{p}/reservations e GET /public/reservations/{code}?token= (07). Prezzo
 * ricalcolato dal server (PRICE_CHANGED), versioni di condizioni e informativa, Idempotency-Key, limite
 * per IP ed email, token di accesso nella risposta e mai negli URL, nessun dato personale nello stato.
 */

beforeEach(function () {
    Carbon::setTestNow('2027-06-01 10:00:00');
    $this->fx = bookableTenant('INSTANT');
    $this->url = "/api/v1/public/{$this->fx['slug']}/{$this->fx['property_slug']}/reservations";
});
afterEach(fn () => Carbon::setTestNow());

function bookingBody(array $fx, array $overrides = []): array
{
    return array_replace_recursive([
        'unit_id' => $fx['unit'], 'check_in' => '2027-07-10', 'check_out' => '2027-07-14', 'adults' => 2, 'children' => 0,
        'guest' => ['first_name' => 'Ada', 'last_name' => 'Lovelace', 'email' => 'ada@example.test', 'phone' => '+44 20 7946 0000', 'country_code' => 'GB',
            'notes' => 'Arrivo in serata'],
        'locale' => 'en',
        'accepted_terms_version' => LegalAcceptance::version('https://example.test/termini'),
        'accepted_privacy_version' => LegalAcceptance::version('https://example.test/privacy'),
        'expected_total_cents' => 54000,
    ], $overrides);
}

function book(TestCase $test, string $url, array $body, ?string $key = null): TestResponse
{
    return $test->withHeaders(['Idempotency-Key' => $key ?? uuid7(), 'Accept-Language' => 'en'])->postJson($url, $body);
}

it('avvia la prenotazione: AWAITING_PAYMENT, URL di checkout e token di accesso', function () {
    $response = book($this, $this->url, bookingBody($this->fx))
        ->assertCreated()
        ->assertJsonPath('status', 'AWAITING_PAYMENT');

    $code = $response->json('reservation_code');
    $token = $response->json('access_token');

    expect($code)->toMatch('/^[A-Z0-9]{8}$/')
        ->and($token)->toMatch('/^[a-f0-9]{64}$/')
        ->and($response->json('checkout_url'))->toContain('/_simulated-checkout/cs_fake_')
        ->and($response->json('checkout_expires_at'))->toBe('2027-06-01T10:32:00Z')
        ->and($response->headers->getCookies())->toBe([]);

    $r = inTenant($this->fx['tenant'], fn () => Reservation::query()->with('guest')->where('code', $code)->firstOrFail());
    expect($r->channel->value)->toBe('DIRECT_WEB')
        ->and($r->guest->email)->toBe('ada@example.test')
        ->and($r->guest->notes)->toBe('Arrivo in serata')
        ->and($r->contract_total_cents)->toBe(54000);

    // Le versioni accettate sono nell'audit; nessun dato personale.
    $audit = inTenant($this->fx['tenant'], fn () => AuditLog::query()->where('subject_id', $r->id)->where('action', 'reservation.terms_accepted')->firstOrFail());
    expect($audit->changes)->toBe(['terms_version' => LegalAcceptance::version('https://example.test/termini'), 'privacy_version' => LegalAcceptance::version('https://example.test/privacy')])
        ->and(inTenant($this->fx['tenant'], fn () => AuditLog::query()->where('subject_id', $r->id)->get()->toJson()))->not->toContain('ada@example.test')->not->toContain('Lovelace');

    // La success_url della sessione non contiene il token (05).
    $page = fakeGateway()->checkoutPage(substr((string) $response->json('checkout_url'), strrpos((string) $response->json('checkout_url'), '/') + 1));
    expect($page['success_url'])->not->toContain($token);
});

it('prezzo cambiato: 409 PRICE_CHANGED con il nuovo prezzo e nessuna prenotazione', function () {
    book($this, $this->url, bookingBody($this->fx, ['expected_total_cents' => 50000]))
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'PRICE_CHANGED')
        ->assertJsonPath('error.details.price.total.amount_cents', 54000)
        ->assertJsonPath('error.details.price.lines.0.kind', 'NIGHT');

    expect(inTenant($this->fx['tenant'], fn () => Reservation::query()->count()))->toBe(0);
});

it('richiede l\'accettazione delle versioni attuali di informativa e condizioni', function () {
    book($this, $this->url, bookingBody($this->fx, ['accepted_privacy_version' => 'vecchia']))
        ->assertStatus(400)->assertJsonStructure(['error' => ['details' => ['fields' => ['accepted_privacy_version']]]]);
    book($this, $this->url, bookingBody($this->fx, ['accepted_terms_version' => null]))
        ->assertStatus(400)->assertJsonStructure(['error' => ['details' => ['fields' => ['accepted_terms_version']]]]);

    // Struttura senza condizioni pubblicate: basta l'informativa.
    inTenant($this->fx['tenant'], fn () => DB::table('properties')->where('id', $this->fx['property'])->update(['terms_url' => null]));
    book($this, $this->url, bookingBody($this->fx, ['accepted_terms_version' => null]))->assertCreated();
});

it('rifiuta date occupate, minimo di notti, unità di altre strutture e dati dell\'ospite non validi', function () {
    book($this, $this->url, bookingBody($this->fx))->assertCreated();
    book($this, $this->url, bookingBody($this->fx, ['check_in' => '2027-07-12', 'check_out' => '2027-07-16']))
        ->assertStatus(409)->assertJsonPath('error.code', 'DATES_UNAVAILABLE');
    book($this, $this->url, bookingBody($this->fx, ['check_in' => '2027-07-20', 'check_out' => '2027-07-22', 'expected_total_cents' => 30000]))
        ->assertStatus(422)->assertJsonPath('error.code', 'MIN_NIGHTS_NOT_MET');

    $other = bookableTenant('INSTANT');
    book($this, $this->url, bookingBody($this->fx, ['unit_id' => $other['unit'], 'check_in' => '2027-08-01', 'check_out' => '2027-08-05']))->assertNotFound();

    book($this, $this->url, bookingBody($this->fx, ['guest' => ['country_code' => 'ita', 'email' => 'x']]))
        ->assertStatus(400)->assertJsonStructure(['error' => ['details' => ['fields' => ['guest.country_code', 'guest.email']]]]);
});

it('pagamenti non attivi: 422 PAYMENTS_NOT_ENABLED senza prenotazione né occupazione', function () {
    inTenant($this->fx['tenant'], fn () => DB::table('stripe_accounts')->update(['charges_enabled' => false]));

    book($this, $this->url, bookingBody($this->fx))->assertStatus(422)->assertJsonPath('error.code', 'PAYMENTS_NOT_ENABLED');
    expect(inTenant($this->fx['tenant'], fn () => Reservation::query()->count()))->toBe(0)
        ->and(occupancyViolations($this->fx['tenant']))->toBe([]);
});

it('servizio di pagamento irraggiungibile: 503, la prenotazione resta in attesa con la scadenza provvisoria', function () {
    fakeGateway()->failNext(FakeOperation::CREATE, FakeFailure::TIMEOUT);

    book($this, $this->url, bookingBody($this->fx))->assertStatus(503)->assertJsonPath('error.code', 'PAYMENT_PROVIDER_UNAVAILABLE');

    $r = inTenant($this->fx['tenant'], fn () => Reservation::query()->firstOrFail());
    expect($r->status->value)->toBe('AWAITING_PAYMENT')->and(occupancyViolations($this->fx['tenant']))->toBe([]);
});

it('stessa Idempotency-Key: stessa risposta e una sola prenotazione; senza chiave 400', function () {
    $key = uuid7();
    $first = book($this, $this->url, bookingBody($this->fx), $key)->assertCreated();
    book($this, $this->url, bookingBody($this->fx), $key)->assertCreated()
        ->assertHeader('Idempotent-Replayed', 'true')
        ->assertJsonPath('reservation_code', $first->json('reservation_code'));
    book($this, $this->url, bookingBody($this->fx, ['adults' => 3, 'expected_total_cents' => 54000]), $key)
        ->assertStatus(422)->assertJsonPath('error.code', 'IDEMPOTENCY_KEY_REUSED');

    $this->flushHeaders()->postJson($this->url, bookingBody($this->fx))->assertStatus(400);
    expect(inTenant($this->fx['tenant'], fn () => Reservation::query()->count()))->toBe(1);
});

it('limita le prenotazioni per IP e per email', function () {
    config(['security.public_booking_rate_limit' => ['attempts' => 2, 'minutes' => 10]]);
    $stays = [['2027-07-01', '2027-07-04'], ['2027-07-10', '2027-07-14'], ['2027-08-20', '2027-08-24']];

    foreach ($stays as $i => [$in, $out]) {
        $response = book($this, $this->url, bookingBody($this->fx, ['check_in' => $in, 'check_out' => $out, 'expected_total_cents' => $i === 0 ? 42000 : 54000]));
        $i < 2 ? $response->assertCreated() : $response->assertStatus(429)->assertJsonPath('error.code', 'RATE_LIMITED');
    }

    // Stessa email da un altro IP: bloccata dal limite per email.
    $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.9']);
    book($this, $this->url, bookingBody($this->fx, ['check_in' => '2027-09-01', 'check_out' => '2027-09-05']))->assertStatus(429);
});

it('stato per la pagina di ritorno: solo con il token, senza dati personali', function () {
    $created = book($this, $this->url, bookingBody($this->fx))->assertCreated();
    $code = $created->json('reservation_code');
    $token = $created->json('access_token');

    $status = $this->getJson("/api/v1/public/reservations/{$code}?token={$token}", ['Accept-Language' => 'en'])
        ->assertOk()
        ->assertJsonPath('status', 'AWAITING_PAYMENT')
        ->assertJsonPath('check_in', '2027-07-10')
        ->assertJsonPath('contract_total.amount_cents', 54000)
        ->assertJsonPath('economic_status', 'UNPAID')
        ->assertJsonPath('cancellation_policy.code', 'FLEXIBLE_7D');
    expect($status->getContent())->not->toContain('ada@example.test')->not->toContain('Lovelace')->not->toContain('+44');

    $this->getJson("/api/v1/public/reservations/{$code}")->assertNotFound();
    $this->getJson("/api/v1/public/reservations/{$code}?token=".str_repeat('0', 64))->assertNotFound();
    $this->getJson('/api/v1/public/reservations/ZZZZZZZZ?token='.$token)->assertNotFound();

    $reservation = inTenant($this->fx['tenant'], fn () => Reservation::query()->where('code', $code)->firstOrFail());
    simulateCustomer($this->fx, $reservation, FakeCustomerAction::PAY);

    $this->getJson("/api/v1/public/reservations/{$code}?token={$token}")
        ->assertJsonPath('status', 'CONFIRMED')
        ->assertJsonPath('economic_status', 'DEPOSIT_PAID')
        ->assertJsonPath('paid.amount_cents', 16200);
});

it('ignora un tenant_id nel corpo', function () {
    $other = bookableTenant('INSTANT');
    book($this, $this->url, bookingBody($this->fx, ['tenant_id' => $other['tenant']]))->assertCreated();

    expect(inTenant($other['tenant'], fn () => Reservation::query()->count()))->toBe(0)
        ->and(inTenant($this->fx['tenant'], fn () => Reservation::query()->count()))->toBe(1);
});
