<?php

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/*
 * I5 (08 "Test di isolamento") sulle tabelle reali, come app_runtime: senza contesto 0 righe; con il
 * contesto di A solo le righe di A; scritture verso righe di B impossibili. Le policy sono identiche
 * su tutte le tabelle tenant-scoped (RlsCatalogTest): qui si verifica il comportamento su tenants e su
 * una catena struttura -> unità -> prenotazione.
 */

beforeEach(function () {
    $this->t = twoTenantsWithUnits();
});

it('senza contesto app_runtime non vede tenant, strutture né unità', function () {
    expect(DB::table('tenants')->count())->toBe(0)
        ->and(DB::table('properties')->count())->toBe(0)
        ->and(DB::table('units')->count())->toBe(0)
        ->and(DB::table('tenant_members')->count())->toBe(0);
});

it('con il contesto di A vede solo il tenant e le righe di A', function () {
    $a = $this->t['a'];

    [$tenants, $units] = inTenant($a['tenant'], fn () => [
        DB::table('tenants')->pluck('id')->all(),
        DB::table('units')->pluck('id')->all(),
    ]);

    expect($tenants)->toBe([$a['tenant']])->and($units)->toBe([$a['unit']]);
});

it('con il contesto di A non modifica né cancella righe di B', function () {
    $b = $this->t['b'];

    [$updated, $deleted] = inTenant($this->t['a']['tenant'], fn () => [
        DB::table('units')->where('id', $b['unit'])->update(['name' => 'modificata']),
        DB::table('properties')->where('id', $b['property'])->delete(),
    ]);

    expect($updated)->toBe(0)->and($deleted)->toBe(0)
        ->and(inTenant($b['tenant'], fn () => DB::table('units')->where('id', $b['unit'])->value('name')))
        ->toBe('Unità di prova');
});

it('non sposta una riga di A verso il tenant B', function () {
    $a = $this->t['a'];

    inTenant($a['tenant'], fn () => DB::table('properties')->where('id', $a['property'])->update(['tenant_id' => $this->t['b']['tenant']]));
})->throws(QueryException::class, 'row-level security');

it('non crea un tenant con un id diverso dal contesto', function () {
    inTenant($this->t['a']['tenant'], fn () => DB::table('tenants')->insert([
        'id' => uuid7(), 'name' => 'Intruso', 'slug' => 'intruso',
    ]));
})->throws(QueryException::class, 'row-level security');

it('app_admin_ops vede tutti i tenant ma non scrive', function () {
    $adminOps = DB::connection('pgsql_admin_ops');

    // I dati dei test sono nella transazione di RefreshDatabase: si verifica il privilegio, non le righe.
    expect(DB::selectOne("select has_table_privilege('app_admin_ops', 'public.units', 'UPDATE') as g")->g)->toBeFalse()
        ->and($adminOps->selectOne('select current_user as u')->u)->toBe('app_admin_ops');
});
