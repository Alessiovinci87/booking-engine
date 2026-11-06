<?php

use Illuminate\Support\Facades\DB;

/*
 * AC11, I5: isolamento tra tenant via API. Il tenant della richiesta viene dalla membership
 * dell'utente autenticato; una risorsa di un altro tenant risponde 404, mai 403 (07, 08).
 */

it('GET /admin/me restituisce solo il tenant della membership', function () {
    $tenants = twoTenantsWithUnits();
    $a = panelUser($tenants['a']['tenant']);

    actingAsPanelUser($this, $a['user'])
        ->getJson('/api/v1/admin/me')
        ->assertOk()
        ->assertJsonPath('tenant.id', $tenants['a']['tenant'])
        ->assertJsonPath('user.id', $a['user']->id)
        ->assertJsonPath('role', 'OWNER');
});

it('ignora tenant_id inviato dal client in query, body e header', function () {
    $tenants = twoTenantsWithUnits();
    $a = panelUser($tenants['a']['tenant']);

    actingAsPanelUser($this, $a['user'])
        ->withHeader('X-Tenant-Id', $tenants['b']['tenant'])
        ->getJson('/api/v1/admin/me?tenant_id='.$tenants['b']['tenant'])
        ->assertOk()
        ->assertJsonPath('tenant.id', $tenants['a']['tenant']);
});

it('legge una struttura del proprio tenant', function () {
    $tenants = twoTenantsWithUnits();
    $a = panelUser($tenants['a']['tenant']);

    actingAsPanelUser($this, $a['user'])
        ->getJson('/api/v1/admin/properties/'.$tenants['a']['property'])
        ->assertOk()
        ->assertJsonPath('id', $tenants['a']['property']);
});

it('risponde 404 NOT_FOUND per una struttura di un altro tenant', function () {
    $tenants = twoTenantsWithUnits();
    $a = panelUser($tenants['a']['tenant']);

    actingAsPanelUser($this, $a['user'])
        ->getJson('/api/v1/admin/properties/'.$tenants['b']['property'])
        ->assertNotFound()
        ->assertJsonPath('error.code', 'NOT_FOUND');

    // La struttura esiste davvero: è l'isolamento a nasconderla.
    expect(inTenant($tenants['b']['tenant'], fn () => DB::table('properties')->where('id', $tenants['b']['property'])->exists()))->toBeTrue();
});

it('risponde 404 a un utente senza membership', function () {
    $tenants = twoTenantsWithUnits();
    $orphan = panelUser($tenants['a']['tenant']);
    inTenant($tenants['a']['tenant'], fn () => DB::table('tenant_members')->where('user_id', $orphan['user']->id)->delete());

    actingAsPanelUser($this, $orphan['user'])
        ->getJson('/api/v1/admin/me')
        ->assertNotFound()
        ->assertJsonPath('error.code', 'NOT_FOUND');
});

it('risponde 401 UNAUTHENTICATED senza sessione', function () {
    panelGet($this, '/api/v1/admin/me')
        ->assertUnauthorized()
        ->assertJsonPath('error.code', 'UNAUTHENTICATED');
});
