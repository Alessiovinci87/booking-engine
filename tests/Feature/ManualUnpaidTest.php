<?php

use App\Domain\Inventory\Enums\OccupancyPhase;
use App\Domain\Inventory\InventoryService;
use App\Domain\Reservations\Models\Reservation;
use App\Domain\Tenancy\Models\AuditLog;
use Illuminate\Support\Carbon;

/*
 * AC17 (01) e I11 (02): il gestore crea una prenotazione MANUAL già CONFIRMED senza pagamento (stato
 * economico UNPAID, evidenziato nel pannello) e poi registra uno o più pagamenti OFFLINE (05 flusso D).
 * Prezzo calcolato dal server con lo sconto facoltativo.
 */

beforeEach(function () {
    Carbon::setTestNow('2027-06-01 10:00:00');
    $this->fx = bookableTenant('INSTANT');
    $this->owner = signedInOwner($this, $this->fx['tenant']);
});
afterEach(fn () => Carbon::setTestNow());

function manualBody(array $fx, array $overrides = []): array
{
    return array_replace_recursive([
        'unit_id' => $fx['unit'], 'check_in' => '2027-07-10', 'check_out' => '2027-07-14', 'adults' => 2, 'children' => 0,
        'guest' => ['first_name' => 'Grace', 'last_name' => 'Hopper', 'email' => 'Grace@Example.test', 'phone' => '+39 333 1111111', 'country_code' => 'US'],
        'locale' => 'it',
    ], $overrides);
}

it('crea una prenotazione manuale CONFIRMED amministrativa e UNPAID, poi registra pagamenti offline', function () {
    $response = panelPost($this, '/api/v1/admin/reservations', manualBody($this->fx))
        ->assertCreated()
        ->assertJsonPath('status', 'CONFIRMED')
        ->assertJsonPath('channel', 'MANUAL')
        ->assertJsonPath('confirmation_kind', 'ADMINISTRATIVE')
        ->assertJsonPath('economic_status', 'UNPAID')
        ->assertJsonPath('contract_total.amount_cents', 54000)
        ->assertJsonPath('outstanding.amount_cents', 54000)
        ->assertJsonPath('guest.email', 'grace@example.test')
        ->assertJsonCount(0, 'payments');
    $id = $response->json('id');

    $occupancy = inTenant($this->fx['tenant'], fn () => app(InventoryService::class)->activeFor(Reservation::query()->findOrFail($id)));
    expect($occupancy->phase)->toBe(OccupancyPhase::FIRM);

    panelPost($this, "/api/v1/admin/reservations/{$id}/offline-payments", ['amount_cents' => 20000, 'method' => 'BANK_TRANSFER'])
        ->assertCreated()
        ->assertJsonPath('economic_status', 'DEPOSIT_PAID')
        ->assertJsonPath('outstanding.amount_cents', 34000)
        ->assertJsonPath('payments.0.purpose', 'OFFLINE')
        ->assertJsonPath('payments.0.method_offline', 'BANK_TRANSFER');

    panelPost($this, "/api/v1/admin/reservations/{$id}/offline-payments", ['amount_cents' => 34000, 'method' => 'CASH'])
        ->assertCreated()
        ->assertJsonPath('economic_status', 'PAID')
        ->assertJsonPath('outstanding.amount_cents', 0)
        ->assertJsonCount(2, 'payments');

    // Lo stato della prenotazione non cambia; l'audit registra i pagamenti senza dati personali.
    $r = reloadReservation($this->fx, $id);
    expect($r->status->value)->toBe('CONFIRMED')
        ->and(inTenant($this->fx['tenant'], fn () => AuditLog::query()->where('action', 'payment.offline_recorded')->count()))->toBe(2);
});

it('applica lo sconto con una riga DISCOUNT e rifiuta uno sconto oltre il subtotale', function () {
    panelPost($this, '/api/v1/admin/reservations', manualBody($this->fx, ['discount_cents' => 4000]))
        ->assertCreated()
        ->assertJsonPath('contract_total.amount_cents', 50000)
        ->assertJsonPath('price_lines.5.kind', 'DISCOUNT')
        ->assertJsonPath('price_lines.5.amount.amount_cents', -4000);

    panelPost($this, '/api/v1/admin/reservations', manualBody($this->fx, ['check_in' => '2027-07-20', 'check_out' => '2027-07-23', 'discount_cents' => 999999]))
        ->assertStatus(400)->assertJsonPath('error.code', 'VALIDATION_FAILED');
});

it('registra il pagamento offline iniziale nella stessa richiesta', function () {
    panelPost($this, '/api/v1/admin/reservations', manualBody($this->fx, ['offline_payment' => ['amount_cents' => 54000, 'method' => 'POS']]))
        ->assertCreated()
        ->assertJsonPath('economic_status', 'PAID')
        ->assertJsonPath('payments.0.method_offline', 'POS');
});

it('rifiuta importi offline fuori dal residuo e su prenotazioni non confermate', function () {
    $id = panelPost($this, '/api/v1/admin/reservations', manualBody($this->fx))->json('id');

    panelPost($this, "/api/v1/admin/reservations/{$id}/offline-payments", ['amount_cents' => 54001, 'method' => 'CASH'])
        ->assertStatus(400)->assertJsonStructure(['error' => ['details' => ['fields' => ['amount_cents']]]]);
    panelPost($this, "/api/v1/admin/reservations/{$id}/offline-payments", ['amount_cents' => 100, 'method' => 'CHEQUE'])
        ->assertStatus(400);

    $waiting = startCheckout($this->fx, '[2027-07-20,2027-07-24)');
    panelPost($this, "/api/v1/admin/reservations/{$waiting->id}/offline-payments", ['amount_cents' => 100, 'method' => 'CASH'])
        ->assertStatus(409)->assertJsonPath('error.code', 'ILLEGAL_TRANSITION');
});

it('rifiuta date occupate, dati mancanti e un tenant_id nel corpo non cambia il tenant', function () {
    panelPost($this, '/api/v1/admin/reservations', manualBody($this->fx))->assertCreated();
    panelPost($this, '/api/v1/admin/reservations', manualBody($this->fx, ['check_in' => '2027-07-12', 'check_out' => '2027-07-16']))
        ->assertStatus(409)->assertJsonPath('error.code', 'DATES_UNAVAILABLE');

    panelPost($this, '/api/v1/admin/reservations', manualBody($this->fx, ['check_in' => '2027-07-20', 'check_out' => '2027-07-24', 'guest' => ['email' => 'non-una-email']]))
        ->assertStatus(400)->assertJsonStructure(['error' => ['details' => ['fields' => ['guest.email']]]]);

    $other = bookableTenant('INSTANT');
    panelPost($this, '/api/v1/admin/reservations', manualBody($other, ['tenant_id' => $other['tenant']]))->assertNotFound();
    expect(inTenant($other['tenant'], fn () => Reservation::query()->count()))->toBe(0);
});

it('richiede la Idempotency-Key e restituisce la stessa risposta a una ripetizione', function () {
    $this->withHeaders(['Referer' => TEST_PANEL_ORIGIN.'/app'])->postJson('/api/v1/admin/reservations', manualBody($this->fx))
        ->assertStatus(400);

    $key = uuid7();
    $first = panelPost($this, '/api/v1/admin/reservations', manualBody($this->fx), $key)->assertCreated();
    panelPost($this, '/api/v1/admin/reservations', manualBody($this->fx), $key)->assertCreated()->assertJsonPath('id', $first->json('id'));

    expect(inTenant($this->fx['tenant'], fn () => Reservation::query()->count()))->toBe(1);
});
