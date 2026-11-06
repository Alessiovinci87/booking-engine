<?php

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/*
 * I17 (02 "Integrità tra tenant", ADR-0005; 08 "Test di isolamento"): sotto il contesto del tenant A
 * il database rifiuta qualsiasi riga che referenzi una riga del tenant B, anche se il codice sbaglia.
 * Le FK semplici ignorano RLS: la garanzia viene dalle FK composite (tenant_id, parent_id).
 */

beforeEach(function () {
    $this->t = twoTenantsWithUnits();
});

it('rifiuta una prenotazione del tenant A con unit_id del tenant B', function () {
    $a = $this->t['a'];
    $guest = createGuestRow($a['tenant']);

    inTenant($a['tenant'], fn () => DB::table('reservations')->insert(
        reservationRow($a['tenant'], $this->t['b']['unit'], $guest)
    ));
})->throws(QueryException::class, 'reservations_unit_id_fk');

it('rifiuta una prenotazione con l\'ospite di un altro tenant', function () {
    $a = $this->t['a'];
    $guestB = createGuestRow($this->t['b']['tenant']);

    inTenant($a['tenant'], fn () => DB::table('reservations')->insert(reservationRow($a['tenant'], $a['unit'], $guestB)));
})->throws(QueryException::class, 'reservations_guest_id_fk');

it('rifiuta un\'unità del tenant A in una struttura del tenant B', function () {
    $a = $this->t['a'];

    inTenant($a['tenant'], fn () => DB::table('units')->insert([
        'id' => uuid7(), 'tenant_id' => $a['tenant'], 'property_id' => $this->t['b']['property'],
        'name' => 'intrusa', 'base_occupancy' => 1, 'max_occupancy' => 2,
    ]));
})->throws(QueryException::class, 'units_property_id_fk');

it('rifiuta un\'occupazione del tenant A su un\'unità del tenant B', function () {
    $a = $this->t['a'];
    $id = uuid7();

    inTenant($a['tenant'], fn () => DB::table('inventory_occupancies')->insert([
        'id' => $id, 'tenant_id' => $a['tenant'], 'unit_id' => $this->t['b']['unit'],
        'stay' => '[2027-08-10,2027-08-17)', 'kind' => 'MANUAL_BLOCK', 'phase' => 'FIRM',
        'status' => 'ACTIVE', 'ics_uid' => "occ-{$id}@example.test",
    ]));
})->throws(QueryException::class, 'inventory_occupancies_unit_id_fk');

it('rifiuta un pagamento del tenant A su una prenotazione del tenant B', function () {
    $b = $this->t['b'];
    $reservationB = reservationRow($b['tenant'], $b['unit'], createGuestRow($b['tenant']));
    inTenant($b['tenant'], fn () => DB::table('reservations')->insert($reservationB));

    inTenant($this->t['a']['tenant'], fn () => DB::table('payments')->insert([
        'id' => uuid7(), 'tenant_id' => $this->t['a']['tenant'], 'reservation_id' => $reservationB['id'],
        'purpose' => 'DEPOSIT', 'amount_cents' => 3000, 'capture_method' => 'AUTOMATIC', 'status' => 'PENDING',
    ]));
})->throws(QueryException::class, 'payments_reservation_id_fk');

it('rifiuta una riga con il tenant_id di B sotto il contesto di A (RLS WITH CHECK)', function () {
    $b = $this->t['b'];

    inTenant($this->t['a']['tenant'], fn () => DB::table('units')->insert([
        'id' => uuid7(), 'tenant_id' => $b['tenant'], 'property_id' => $b['property'],
        'name' => 'intrusa', 'base_occupancy' => 1, 'max_occupancy' => 2,
    ]));
})->throws(QueryException::class, 'row-level security');

it('accetta i riferimenti dentro lo stesso tenant', function () {
    $a = $this->t['a'];
    $row = reservationRow($a['tenant'], $a['unit'], createGuestRow($a['tenant']));

    inTenant($a['tenant'], fn () => DB::table('reservations')->insert($row));

    expect(inTenant($a['tenant'], fn () => DB::table('reservations')->count()))->toBe(1);
});
