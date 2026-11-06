<?php

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;

/*
 * 3.2: AvailabilityService e GET /public/{t}/{p}/availability (02 "Disponibilità effettiva", 07).
 * Tre fonti (occupazioni attive anche scadute, eventi esterni ACTIVE/PENDING_REMOVAL, conflitti
 * OPEN/INVESTIGATING), notti passate non vendibili, minimi di notti per notte, intervallo massimo
 * 18 mesi. quote-price verifica disponibilità (DATES_UNAVAILABLE) e unità prenotabile
 * (UNIT_NOT_BOOKABLE, ADR-0010).
 */

beforeEach(function () {
    Carbon::setTestNow('2027-06-01 10:00:00');
    $this->tenantSlug = 'tenant-av-'.substr(uuid7(), -6);
    $this->tenant = createTenantRow($this->tenantSlug);
    $this->golden = goldenUnitRows($this->tenant);
    $this->unit = $this->golden['unit'];
    $this->propertySlug = inTenant($this->tenant, function () {
        DB::table('properties')->where('id', $this->golden['property'])->update(['status' => 'PUBLISHED']);

        return DB::table('properties')->where('id', $this->golden['property'])->value('slug');
    });
    $this->base = "/api/v1/public/{$this->tenantSlug}/{$this->propertySlug}";
});

afterEach(fn () => Carbon::setTestNow());

function availability(object $test, string $from, string $to, ?string $unit = null): TestResponse
{
    return $test->getJson($test->base.'/availability?'.http_build_query(['unit_id' => $unit ?? $test->unit, 'from' => $from, 'to' => $to]));
}

function insertOccupancyRow(string $tenant, string $unit, string $stay, array $overrides = []): void
{
    inTenant($tenant, fn () => DB::table('inventory_occupancies')->insert(array_merge([
        'id' => $id = uuid7(), 'tenant_id' => $tenant, 'unit_id' => $unit, 'stay' => $stay,
        'kind' => 'MANUAL_BLOCK', 'phase' => 'FIRM', 'status' => 'ACTIVE', 'ics_uid' => "occ-{$id}@example.test",
        'created_at' => now(), 'updated_at' => now(),
    ], $overrides)));
}

it('elenca le notti non vendibili dalle tre fonti, senza il motivo', function () {
    insertOccupancyRow($this->tenant, $this->unit, '[2027-08-10,2027-08-12)');
    createExternalEventRow($this->tenant, $this->unit, '[2027-08-14,2027-08-15)', 'PENDING_REMOVAL');
    createConflictRow($this->tenant, $this->unit, '[2027-08-16,2027-08-17)', 'INVESTIGATING');
    // Non bloccano: occupazione rilasciata, evento rimosso, conflitto risolto.
    insertOccupancyRow($this->tenant, $this->unit, '[2027-08-20,2027-08-22)', ['status' => 'RELEASED', 'released_at' => now(), 'release_reason' => 'prova']);
    createExternalEventRow($this->tenant, $this->unit, '[2027-08-22,2027-08-23)', 'REMOVED');
    createConflictRow($this->tenant, $this->unit, '[2027-08-23,2027-08-24)', 'RESOLVED');

    $response = availability($this, '2027-08-08', '2027-08-25')
        ->assertOk()
        ->assertJsonPath('unit_id', $this->unit)
        ->assertJsonPath('unavailable_nights', ['2027-08-10', '2027-08-11', '2027-08-14', '2027-08-16']);

    expect(json_encode($response->json()))->not->toContain('MANUAL_BLOCK')->not->toContain('BOOKING');
});

it('considera ancora occupata un\'occupazione scaduta ma non rilasciata (02)', function () {
    insertOccupancyRow($this->tenant, $this->unit, '[2027-08-10,2027-08-11)', [
        'kind' => 'MANUAL_BLOCK', 'phase' => 'FIRM', 'expires_at' => now()->subHour(),
    ]);

    availability($this, '2027-08-09', '2027-08-12')->assertJsonPath('unavailable_nights', ['2027-08-10']);
});

it('non vende le notti già passate nel fuso della struttura', function () {
    Carbon::setTestNow('2027-08-10 23:30:00'); // 01:30 dell'11 agosto a Roma

    availability($this, '2027-08-09', '2027-08-13')->assertJsonPath('unavailable_nights', ['2027-08-09', '2027-08-10']);
});

it('restituisce il minimo dell\'unità e i minimi diversi per notte', function () {
    availability($this, '2027-07-30', '2027-08-02')
        ->assertJsonPath('min_nights', 3)
        ->assertJsonPath('min_nights_by_night', ['2027-08-01' => 5]);

    availability($this, '2027-07-01', '2027-07-03')->assertJsonPath('min_nights_by_night', []);
    expect(availability($this, '2027-07-01', '2027-07-03')->getContent())->toContain('"min_nights_by_night":{}');
});

it('limita l\'intervallo a 18 mesi e valida i parametri', function () {
    availability($this, '2027-06-01', '2028-12-01')->assertOk();
    availability($this, '2027-06-01', '2028-12-02')->assertStatus(400)->assertJsonStructure(['error' => ['details' => ['fields' => ['to']]]]);
    availability($this, '2027-06-05', '2027-06-01')->assertStatus(400);
    $this->getJson($this->base.'/availability?from=2027-06-01&to=2027-06-05')->assertStatus(400);
});

it('risponde 409 UNIT_NOT_BOOKABLE per unità sospese o non attive e 404 per unità di altri', function (array $change) {
    inTenant($this->tenant, fn () => DB::table('units')->where('id', $this->unit)->update($change));

    availability($this, '2027-08-01', '2027-08-10')->assertStatus(409)->assertJsonPath('error.code', 'UNIT_NOT_BOOKABLE');
    $this->postJson($this->base.'/quote-price', ['unit_id' => $this->unit, 'check_in' => '2027-07-01', 'check_out' => '2027-07-05', 'adults' => 2])
        ->assertStatus(409)->assertJsonPath('error.code', 'UNIT_NOT_BOOKABLE');
})->with([
    'vendite sospese' => [['sales_suspended' => true]],
    'non attiva' => [['status' => 'INACTIVE']],
]);

it('non rivela unità di altri tenant', function () {
    availability($this, '2027-08-01', '2027-08-10', twoTenantsWithUnits()['a']['unit'])->assertNotFound();
});

it('quote-price rifiuta date non disponibili con 409 DATES_UNAVAILABLE e le notti', function () {
    insertOccupancyRow($this->tenant, $this->unit, '[2027-08-12,2027-08-14)');

    $this->postJson($this->base.'/quote-price', ['unit_id' => $this->unit, 'check_in' => '2027-08-10', 'check_out' => '2027-08-17', 'adults' => 4, 'children' => 1])
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'DATES_UNAVAILABLE')
        ->assertJsonPath('error.details', ['unit_id' => $this->unit, 'nights' => ['2027-08-12', '2027-08-13']]);

    $this->postJson($this->base.'/quote-price', ['unit_id' => $this->unit, 'check_in' => '2027-08-14', 'check_out' => '2027-08-21', 'adults' => 2])
        ->assertOk();
});
