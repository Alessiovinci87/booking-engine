<?php

/*
 * 2.4: anteprima prezzo del pannello (07, POST /admin/units/{id}/price-preview): stesso calcolo della
 * pagina ospite sui dati salvati. Golden di 04 via API, errori 422, validazioni, isolamento.
 */

beforeEach(function () {
    $this->owner = signedInOwner($this);
    $this->golden = goldenUnitRows($this->owner['tenant']);
    $this->url = "/api/v1/admin/units/{$this->golden['unit']}/price-preview";
});

it('restituisce il prezzo golden di 04 dai dati salvati', function () {
    $response = $this->postJson($this->url, ['check_in' => '2027-08-10', 'check_out' => '2027-08-17', 'adults' => 4, 'children' => 1])
        ->assertOk()
        ->assertJsonPath('nights', 7)
        ->assertJsonPath('guests', 5)
        ->assertJsonPath('required_min_nights', 5)
        ->assertJsonPath('total', ['amount_cents' => 158500, 'currency' => 'EUR'])
        ->assertJsonPath('deposit.amount_cents', 47550)
        ->assertJsonPath('balance.amount_cents', 110950)
        ->assertJsonPath('subtotal.amount_cents', 158500)
        ->assertJsonPath('discount.amount_cents', 0)
        ->assertJsonPath('payment_mode', 'DEPOSIT_30')
        ->assertJsonPath('booking_mode', 'REQUEST')
        ->assertJsonPath('lines.0', [
            'kind' => 'NIGHT', 'date' => '2027-08-10', 'quantity' => 1,
            'unit_amount' => ['amount_cents' => 18000, 'currency' => 'EUR'],
            'amount' => ['amount_cents' => 18000, 'currency' => 'EUR'],
        ]);

    $lines = collect($response->json('lines'));
    expect($lines->where('kind', 'NIGHT'))->toHaveCount(7)
        ->and($lines->where('kind', 'EXTRA_GUEST'))->toHaveCount(7)
        ->and($lines->where('kind', 'CLEANING')->first()['amount']['amount_cents'])->toBe(6000)
        ->and($lines->sum(fn ($l) => $l['amount']['amount_cents']))->toBe(158500)
        // Nessun identificativo interno delle stagioni nella risposta.
        ->and($lines->first())->not->toHaveKey('season_id');
});

it('restituisce gli errori di prezzo di 04 con i dettagli', function (array $body, string $code, array $details) {
    $this->postJson($this->url, $body)
        ->assertStatus(422)
        ->assertJsonPath('error.code', $code)
        ->assertJsonPath('error.details', $details);
})->with([
    'minimo notti di Agosto' => [['check_in' => '2027-08-01', 'check_out' => '2027-08-04', 'adults' => 2], 'MIN_NIGHTS_NOT_MET', ['required_min_nights' => 5, 'nights' => 3]],
    'troppi ospiti' => [['check_in' => '2027-08-10', 'check_out' => '2027-08-17', 'adults' => 6, 'children' => 1], 'OCCUPANCY_EXCEEDED', ['max_occupancy' => 6, 'guests' => 7]],
]);

it('valida date e ospiti', function (array $body, string $field) {
    $this->postJson($this->url, $body)
        ->assertStatus(400)
        ->assertJsonPath('error.code', 'VALIDATION_FAILED')
        ->assertJsonStructure(['error' => ['details' => ['fields' => [$field]]]]);
})->with([
    'check-out uguale al check-in' => [['check_in' => '2027-08-10', 'check_out' => '2027-08-10', 'adults' => 2], 'check_out'],
    'data non valida' => [['check_in' => '2027-02-30', 'check_out' => '2027-03-02', 'adults' => 2], 'check_in'],
    'nessun adulto' => [['check_in' => '2027-08-10', 'check_out' => '2027-08-17', 'adults' => 0], 'adults'],
    'soggiorno oltre un anno' => [['check_in' => '2027-01-01', 'check_out' => '2028-01-02', 'adults' => 2], 'check_out'],
]);

it('risponde 404 per un\'unità di un altro tenant', function () {
    $other = createTenantRow();
    $foreign = goldenUnitRows($other);

    $this->postJson("/api/v1/admin/units/{$foreign['unit']}/price-preview", ['check_in' => '2027-08-10', 'check_out' => '2027-08-17', 'adults' => 2])
        ->assertNotFound()
        ->assertJsonPath('error.code', 'NOT_FOUND');
});
