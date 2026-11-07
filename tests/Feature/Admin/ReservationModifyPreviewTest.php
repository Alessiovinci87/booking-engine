<?php

use App\Domain\Reservations\Enums\ReservationStatus;
use App\Domain\Reservations\Models\Reservation;
use App\Domain\Reservations\Models\ReservationChange;
use App\Domain\Tenancy\Models\AuditLog;
use Illuminate\Support\Carbon;

/*
 * Decisione dopo la verifica di M3 (ADR-0011 punto 28): prima di salvare una modifica il pannello
 * mostra vecchio e nuovo prezzo (righe e totale) tramite un'anteprima dal server e chiede conferma.
 * L'anteprima non salva niente; il PATCH con `expected_total_cents` rifiuta un prezzo cambiato.
 */

beforeEach(function () {
    Carbon::setTestNow('2027-06-01 10:00:00');
    $this->fx = bookableTenant('INSTANT');
    signedInOwner($this, $this->fx['tenant']);
    $this->id = panelPost($this, '/api/v1/admin/reservations', [
        'unit_id' => $this->fx['unit'], 'check_in' => '2027-07-10', 'check_out' => '2027-07-14', 'adults' => 2, 'discount_cents' => 1000,
        'guest' => ['first_name' => 'Grace', 'last_name' => 'Hopper', 'email' => 'grace@example.test', 'phone' => '+39 333 1111111', 'country_code' => 'US'],
        'locale' => 'en',
    ])->assertCreated()->json('id');
    $this->preview = "/api/v1/admin/reservations/{$this->id}/modify-preview";
});
afterEach(fn () => Carbon::setTestNow());

it('mostra soggiorno, righe e totale prima e dopo senza salvare nulla', function () {
    $audits = inTenant($this->fx['tenant'], fn () => AuditLog::query()->count());

    $response = panelPost($this, $this->preview, ['check_in' => '2027-07-11', 'check_out' => '2027-07-16', 'adults' => 3])
        ->assertOk()
        ->assertJsonPath('version', 1)
        ->assertJsonPath('current.check_in', '2027-07-10')
        ->assertJsonPath('current.nights', 4)
        ->assertJsonPath('current.contract_total.amount_cents', 53000)
        ->assertJsonPath('proposed.check_in', '2027-07-11')
        ->assertJsonPath('proposed.check_out', '2027-07-16')
        ->assertJsonPath('proposed.adults', 3)
        ->assertJsonPath('proposed.nights', 5)
        // 5 notti × 120 € + pulizia 60 € − sconto 10 € (lo sconto originale resta).
        ->assertJsonPath('proposed.contract_total.amount_cents', 65000)
        ->assertJsonPath('difference.amount_cents', 12000);
    $nights = fn (array $lines): int => count(array_filter($lines, fn (array $l): bool => $l['date'] !== null));
    expect($nights($response->json('proposed.price_lines')))->toBe(5)
        ->and($nights($response->json('current.price_lines')))->toBe(4);

    $r = inTenant($this->fx['tenant'], fn () => Reservation::query()->findOrFail($this->id));
    expect((string) $r->stay)->toBe('[2027-07-10,2027-07-14)')
        ->and($r->version)->toBe(1)
        ->and($r->contract_total_cents)->toBe(53000)
        ->and(inTenant($this->fx['tenant'], fn () => ReservationChange::query()->count()))->toBe(0)
        ->and(inTenant($this->fx['tenant'], fn () => AuditLog::query()->count()))->toBe($audits)
        ->and(occupancyViolations($this->fx['tenant']))->toBe([]);

    // Le date originali restano occupate dalla prenotazione: l'anteprima non le ha spostate.
    panelGet($this, "/api/v1/admin/reservations/{$this->id}")->assertJsonPath('check_in', '2027-07-10')->assertJsonPath('contract_total.amount_cents', 53000);
});

it('restituisce gli stessi errori della modifica', function () {
    reservationIn($this->fx, ReservationStatus::CONFIRMED, '[2027-07-16,2027-07-20)');

    panelPost($this, $this->preview, ['check_in' => '2027-07-12', 'check_out' => '2027-07-18'])
        ->assertStatus(409)->assertJsonPath('error.code', 'DATES_UNAVAILABLE');
    panelPost($this, $this->preview, ['check_in' => '2027-07-12'])->assertStatus(400);

    $cancelled = reservationIn($this->fx, ReservationStatus::CANCELLED, '[2027-07-21,2027-07-25)');
    panelPost($this, "/api/v1/admin/reservations/{$cancelled->id}/modify-preview", ['adults' => 1])
        ->assertStatus(409)->assertJsonPath('error.code', 'ILLEGAL_TRANSITION');

    $foreign = reservationIn(bookableTenant('INSTANT'), ReservationStatus::CONFIRMED);
    panelPost($this, "/api/v1/admin/reservations/{$foreign->id}/modify-preview", ['adults' => 1])->assertNotFound();
});

it('il PATCH con expected_total_cents salva solo se il prezzo è quello dell\'anteprima', function () {
    $url = "/api/v1/admin/reservations/{$this->id}";
    $body = ['check_in' => '2027-07-11', 'check_out' => '2027-07-16'];
    $total = panelPost($this, $this->preview, $body)->json('proposed.contract_total.amount_cents');

    panelPatch($this, $url, [...$body, 'expected_total_cents' => $total - 100], 1)
        ->assertStatus(409)->assertJsonPath('error.code', 'PRICE_CHANGED')
        ->assertJsonPath('error.details.contract_total.amount_cents', $total);

    panelPatch($this, $url, [...$body, 'expected_total_cents' => $total], 1)
        ->assertOk()->assertJsonPath('contract_total.amount_cents', $total)->assertJsonPath('check_in', '2027-07-11');
});
