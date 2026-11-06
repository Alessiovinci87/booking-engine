<?php

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/*
 * I12 (02, 06): un'unità con calendari esterni attivi non può essere INSTANT. Garantito in
 * applicazione (UnitService, 409 EXTERNAL_CALENDARS_ACTIVE) e nel database (trigger, SQLSTATE BE012),
 * da entrambi i lati: unità che diventa INSTANT e calendario attivo aggiunto a un'unità INSTANT.
 */

function insertCalendar(string $tenant, string $unit, string $status = 'ACTIVE'): string
{
    $id = uuid7();

    inTenant($tenant, fn () => DB::table('external_calendars')->insert([
        'id' => $id, 'tenant_id' => $tenant, 'unit_id' => $unit, 'source' => 'AIRBNB',
        'url_encrypted' => 'cifrato-di-prova', 'status' => $status,
        'created_at' => now(), 'updated_at' => now(),
    ]));

    return $id;
}

beforeEach(function () {
    $this->tenants = twoTenantsWithUnits();
    $this->a = $this->tenants['a'];
});

it('rifiuta con 409 il passaggio a INSTANT se l\'unità ha un calendario esterno attivo', function (string $status) {
    signedInOwner($this, $this->a['tenant']);
    insertCalendar($this->a['tenant'], $this->a['unit'], $status);

    panelPatch($this, "/api/v1/admin/units/{$this->a['unit']}", ['booking_mode' => 'INSTANT', 'min_nights' => 9], 1)
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'EXTERNAL_CALENDARS_ACTIVE')
        ->assertJsonPath('error.details.active_calendars', 1);

    // Nessuna modifica parziale.
    $row = inTenant($this->a['tenant'], fn () => DB::table('units')->where('id', $this->a['unit'])->first());
    expect($row->booking_mode)->toBe('REQUEST')->and((int) $row->min_nights)->toBe(1)->and((int) $row->version)->toBe(1);
})->with(['ACTIVE', 'DEGRADED', 'UNHEALTHY']);

it('consente INSTANT senza calendari o con calendari disattivati', function () {
    signedInOwner($this, $this->a['tenant']);
    insertCalendar($this->a['tenant'], $this->a['unit'], 'DISABLED');

    panelPatch($this, "/api/v1/admin/units/{$this->a['unit']}", ['booking_mode' => 'INSTANT'], 1)
        ->assertOk()
        ->assertJsonPath('booking_mode', 'INSTANT');
});

it('il trigger rifiuta un update diretto a INSTANT con calendario attivo', function () {
    insertCalendar($this->a['tenant'], $this->a['unit']);

    expect(fn () => inTenant($this->a['tenant'], fn () => DB::table('units')->where('id', $this->a['unit'])->update(['booking_mode' => 'INSTANT'])))
        ->toThrow(function (QueryException $e) {
            expect($e->getCode())->toBe('BE012');
        });

    expect(inTenant($this->a['tenant'], fn () => DB::table('units')->where('id', $this->a['unit'])->value('booking_mode')))->toBe('REQUEST');
});

it('il trigger rifiuta un insert diretto di calendario attivo su un\'unità INSTANT', function () {
    inTenant($this->a['tenant'], fn () => DB::table('units')->where('id', $this->a['unit'])->update(['booking_mode' => 'INSTANT']));

    expect(fn () => insertCalendar($this->a['tenant'], $this->a['unit']))
        ->toThrow(fn (QueryException $e) => expect($e->getCode())->toBe('BE012'));

    // Un calendario disattivato è ammesso, ma non si può riattivare finché l'unità è INSTANT.
    $calendar = insertCalendar($this->a['tenant'], $this->a['unit'], 'DISABLED');
    expect(fn () => inTenant($this->a['tenant'], fn () => DB::table('external_calendars')->where('id', $calendar)->update(['status' => 'ACTIVE'])))
        ->toThrow(fn (QueryException $e) => expect($e->getCode())->toBe('BE012'));
});

it('il trigger rifiuta un insert diretto di un\'unità INSTANT con calendario attivo spostato su di essa', function () {
    $other = createUnitRow($this->a['tenant'], $this->a['property'], 'Seconda unità');
    inTenant($this->a['tenant'], fn () => DB::table('units')->where('id', $other)->update(['booking_mode' => 'INSTANT']));
    $calendar = insertCalendar($this->a['tenant'], $this->a['unit']);

    expect(fn () => inTenant($this->a['tenant'], fn () => DB::table('external_calendars')->where('id', $calendar)->update(['unit_id' => $other])))
        ->toThrow(fn (QueryException $e) => expect($e->getCode())->toBe('BE012'));
});
