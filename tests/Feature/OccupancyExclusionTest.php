<?php

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/*
 * I1 (02 "Vincoli di inventario"), parte del database: il vincolo GiST occupancies_no_overlap e gli
 * altri vincoli di inventory_occupancies. Le scritture dell'applicazione passeranno solo da
 * InventoryService (3.1); qui si inserisce direttamente per verificare la garanzia del database.
 */

function occupancyRow(string $tenant, string $unit, string $stay, array $overrides = []): array
{
    $id = uuid7();

    return array_merge([
        'id' => $id, 'tenant_id' => $tenant, 'unit_id' => $unit, 'stay' => $stay,
        'kind' => 'MANUAL_BLOCK', 'phase' => 'FIRM', 'status' => 'ACTIVE',
        'ics_uid' => "occ-{$id}@example.test", 'created_at' => now(), 'updated_at' => now(),
    ], $overrides);
}

beforeEach(function () {
    $this->tenant = createTenantRow();
    $this->unit = createUnitRow($this->tenant, createPropertyRow($this->tenant));
});

function insertOccupancy(string $tenant, array $row): void
{
    inTenant($tenant, fn () => DB::table('inventory_occupancies')->insert($row));
}

it('rifiuta due occupazioni attive sovrapposte sulla stessa unità', function () {
    insertOccupancy($this->tenant, occupancyRow($this->tenant, $this->unit, '[2027-08-10,2027-08-17)'));
    insertOccupancy($this->tenant, occupancyRow($this->tenant, $this->unit, '[2027-08-16,2027-08-20)'));
})->throws(QueryException::class, 'occupancies_no_overlap');

it('accetta soggiorni adiacenti: il giorno di check-out non è occupato', function () {
    insertOccupancy($this->tenant, occupancyRow($this->tenant, $this->unit, '[2027-08-10,2027-08-17)'));
    insertOccupancy($this->tenant, occupancyRow($this->tenant, $this->unit, '[2027-08-17,2027-08-20)'));

    expect(inTenant($this->tenant, fn () => DB::table('inventory_occupancies')->count()))->toBe(2);
});

it('accetta una sovrapposizione con un\'occupazione rilasciata', function () {
    insertOccupancy($this->tenant, occupancyRow($this->tenant, $this->unit, '[2027-08-10,2027-08-17)', [
        'status' => 'RELEASED', 'released_at' => now(), 'release_reason' => 'prova',
    ]));
    insertOccupancy($this->tenant, occupancyRow($this->tenant, $this->unit, '[2027-08-12,2027-08-15)'));

    expect(inTenant($this->tenant, fn () => DB::table('inventory_occupancies')->where('status', 'ACTIVE')->count()))->toBe(1);
});

it('accetta sovrapposizioni su unità diverse', function () {
    $other = createUnitRow($this->tenant, createPropertyRow($this->tenant, 'altra-casa'));

    insertOccupancy($this->tenant, occupancyRow($this->tenant, $this->unit, '[2027-08-10,2027-08-17)'));
    insertOccupancy($this->tenant, occupancyRow($this->tenant, $other, '[2027-08-10,2027-08-17)'));

    expect(inTenant($this->tenant, fn () => DB::table('inventory_occupancies')->count()))->toBe(2);
});

it('rifiuta una fase incoerente con il motivo temporaneo', function (array $overrides) {
    insertOccupancy($this->tenant, occupancyRow($this->tenant, $this->unit, '[2027-08-10,2027-08-17)', $overrides));
})->with([
    'FIRM con motivo' => [['phase' => 'FIRM', 'temp_reason' => 'CHECKOUT']],
    'TEMPORARY senza motivo' => [['phase' => 'TEMPORARY', 'temp_reason' => null]],
])->throws(QueryException::class, 'occupancies_phase_consistent');

it('richiede la prenotazione per le occupazioni RESERVATION e vieta la nota', function (array $overrides) {
    insertOccupancy($this->tenant, occupancyRow($this->tenant, $this->unit, '[2027-08-10,2027-08-17)', $overrides));
})->with([
    'RESERVATION senza prenotazione' => [['kind' => 'RESERVATION']],
])->throws(QueryException::class, 'occupancies_kind_consistent');

it('consente una sola occupazione per prenotazione', function () {
    $guest = createGuestRow($this->tenant);
    $reservation = reservationRow($this->tenant, $this->unit, $guest);
    inTenant($this->tenant, fn () => DB::table('reservations')->insert($reservation));

    insertOccupancy($this->tenant, occupancyRow($this->tenant, $this->unit, '[2027-08-10,2027-08-17)', [
        'kind' => 'RESERVATION', 'reservation_id' => $reservation['id'],
        'status' => 'RELEASED', 'released_at' => now(),
    ]));
    insertOccupancy($this->tenant, occupancyRow($this->tenant, $this->unit, '[2027-09-10,2027-09-17)', [
        'kind' => 'RESERVATION', 'reservation_id' => $reservation['id'],
    ]));
})->throws(QueryException::class, 'occupancies_one_per_reservation');
