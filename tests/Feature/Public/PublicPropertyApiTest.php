<?php

use Illuminate\Support\Facades\DB;

/*
 * 2.7: API pubblica (07). GET /public/{t}/{p}: struttura pubblicata, testi nella lingua richiesta con
 * translation_fallback, unitÃ  attive con foto; POST quote-price: stesso calcolo del pannello. Tenant
 * dallo slug, bozze e tenant non attivi â†’ 404, nessun dato interno nella risposta.
 */

beforeEach(function () {
    $this->tenantSlug = 'tenant-pub-'.substr(uuid7(), -6);
    $this->tenant = createTenantRow($this->tenantSlug);
    $this->golden = goldenUnitRows($this->tenant);
    $this->propertySlug = inTenant($this->tenant, fn () => DB::table('properties')->where('id', $this->golden['property'])->value('slug'));

    inTenant($this->tenant, function () {
        $policy = DB::table('cancellation_policies')->insertGetId([
            'id' => uuid7(), 'tenant_id' => $this->tenant, 'property_id' => $this->golden['property'], 'code' => 'FLEXIBLE_7D',
            'created_at' => now(), 'updated_at' => now(),
        ], 'id');
        DB::table('properties')->where('id', $this->golden['property'])->update([
            'status' => 'PUBLISHED', 'default_cancellation_policy_id' => $policy, 'checkin_time' => '15:00:00',
        ]);
        DB::table('property_translations')->insert([
            'id' => uuid7(), 'tenant_id' => $this->tenant, 'property_id' => $this->golden['property'], 'locale' => 'it',
            'name' => 'Casa al mare', 'description' => 'Descrizione italiana', 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('unit_translations')->insert([
            ['id' => uuid7(), 'tenant_id' => $this->tenant, 'unit_id' => $this->golden['unit'], 'locale' => 'it',
                'name' => 'Trilocale', 'created_at' => now(), 'updated_at' => now()],
            ['id' => uuid7(), 'tenant_id' => $this->tenant, 'unit_id' => $this->golden['unit'], 'locale' => 'en',
                'name' => 'Two-bedroom flat', 'created_at' => now(), 'updated_at' => now()],
        ]);
        DB::table('unit_photos')->insert([
            'id' => uuid7(), 'tenant_id' => $this->tenant, 'unit_id' => $this->golden['unit'], 'disk' => 'local',
            'path' => 'units/x/0.webp', 'mime_type' => 'image/webp', 'size_bytes' => 10, 'position' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    });

    $this->inactiveUnit = createUnitRow($this->tenant, $this->golden['property'], 'UnitÃ  chiusa');
    inTenant($this->tenant, fn () => DB::table('units')->where('id', $this->inactiveUnit)->update(['status' => 'INACTIVE']));

    $this->base = "/api/v1/public/{$this->tenantSlug}/{$this->propertySlug}";
});

it('restituisce la struttura pubblicata in italiano con le sole unitÃ  attive', function () {
    $response = $this->getJson($this->base, ['Accept-Language' => 'it-IT,it;q=0.9'])
        ->assertOk()
        ->assertJsonPath('locale', 'it')
        ->assertJsonPath('name', 'Casa al mare')
        ->assertJsonPath('description', 'Descrizione italiana')
        ->assertJsonPath('translation_fallback', false)
        ->assertJsonPath('checkin_time', '15:00')
        ->assertJsonPath('cancellation_policy.code', 'FLEXIBLE_7D')
        ->assertJsonCount(1, 'units')
        ->assertJsonPath('units.0.id', $this->golden['unit'])
        ->assertJsonPath('units.0.name', 'Trilocale')
        ->assertJsonPath('units.0.max_occupancy', 6)
        ->assertJsonPath('units.0.cleaning_fee', ['amount_cents' => 6000, 'currency' => 'EUR'])
        ->assertJsonPath('units.0.cancellation_policy.code', 'FLEXIBLE_7D')
        ->assertJsonCount(1, 'units.0.photos')
        ->assertJsonStructure(['units' => [['photos' => [['urls' => ['large', 'thumbnail']]]]]]);

    $json = $response->json();
    foreach (['id', 'tenant_id', 'status', 'version', 'ota_commission_bps', 'default_cancellation_policy_id'] as $key) {
        expect($json)->not->toHaveKey($key);
    }
    foreach (['sync_health', 'status', 'version', 'sales_suspended', 'tenant_id'] as $key) {
        expect($json['units'][0])->not->toHaveKey($key);
    }
    expect(json_encode($json))->not->toContain($this->tenant)->not->toContain('units/x/0.webp');
});

it('usa l\'inglese come predefinita e segnala la traduzione mancante', function () {
    $this->getJson($this->base)
        ->assertOk()
        ->assertJsonPath('locale', 'en')
        ->assertJsonPath('name', 'Casa al mare')
        ->assertJsonPath('translation_fallback', true)
        ->assertJsonPath('units.0.name', 'Two-bedroom flat')
        ->assertJsonPath('units.0.translation_fallback', false);

    $this->getJson($this->base.'?locale=it', ['Accept-Language' => 'en'])
        ->assertOk()
        ->assertJsonPath('locale', 'it')
        ->assertJsonPath('translation_fallback', false);
});

it('calcola il prezzo golden di 04 senza creare nulla', function () {
    $before = inTenant($this->tenant, fn () => [DB::table('reservations')->count(), DB::table('inventory_occupancies')->count()]);

    $this->postJson($this->base.'/quote-price', [
        'unit_id' => $this->golden['unit'], 'check_in' => '2027-08-10', 'check_out' => '2027-08-17', 'adults' => 4, 'children' => 1,
    ], ['Accept-Language' => 'it'])
        ->assertOk()
        ->assertJsonPath('total', ['amount_cents' => 158500, 'currency' => 'EUR'])
        ->assertJsonPath('deposit.amount_cents', 47550)
        ->assertJsonPath('balance.amount_cents', 110950)
        ->assertJsonPath('booking_mode', 'REQUEST')
        ->assertJsonPath('cancellation_policy.code', 'FLEXIBLE_7D')
        ->assertJsonPath('cancellation_policy.title', __('cancellation.policies.FLEXIBLE_7D.title', [], 'it'));

    expect(inTenant($this->tenant, fn () => [DB::table('reservations')->count(), DB::table('inventory_occupancies')->count()]))->toBe($before);
});

it('restituisce gli errori di prezzo nella lingua richiesta', function () {
    $this->postJson($this->base.'/quote-price', [
        'unit_id' => $this->golden['unit'], 'check_in' => '2027-08-01', 'check_out' => '2027-08-04', 'adults' => 2,
    ], ['Accept-Language' => 'en'])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'MIN_NIGHTS_NOT_MET')
        ->assertJsonPath('error.details', ['required_min_nights' => 5, 'nights' => 3])
        ->assertJsonPath('error.message', __('api.errors.MIN_NIGHTS_NOT_MET', [], 'en'));

    $this->postJson($this->base.'/quote-price', [
        'unit_id' => $this->golden['unit'], 'check_in' => '2027-08-10', 'check_out' => '2027-08-17', 'adults' => 6, 'children' => 1,
    ])->assertStatus(422)->assertJsonPath('error.code', 'OCCUPANCY_EXCEEDED');
});

it('valida il corpo di quote-price', function (array $body, string $field) {
    $this->postJson($this->base.'/quote-price', $body)
        ->assertStatus(400)
        ->assertJsonPath('error.code', 'VALIDATION_FAILED')
        ->assertJsonStructure(['error' => ['details' => ['fields' => [$field]]]]);
})->with([
    'senza unitÃ ' => [['check_in' => '2027-08-10', 'check_out' => '2027-08-17', 'adults' => 2], 'unit_id'],
    'unitÃ  non UUID' => [['unit_id' => 'abc', 'check_in' => '2027-08-10', 'check_out' => '2027-08-17', 'adults' => 2], 'unit_id'],
    'check-out prima del check-in' => [['unit_id' => '01900000-0000-7000-8000-000000000000', 'check_in' => '2027-08-10', 'check_out' => '2027-08-09', 'adults' => 2], 'check_out'],
]);

it('non calcola prezzi per unitÃ  inattive o di altre strutture', function () {
    $body = ['check_in' => '2027-09-10', 'check_out' => '2027-09-13', 'adults' => 2];

    $this->postJson($this->base.'/quote-price', ['unit_id' => $this->inactiveUnit] + $body)
        ->assertNotFound()->assertJsonPath('error.code', 'NOT_FOUND');

    $other = twoTenantsWithUnits()['a'];
    $this->postJson($this->base.'/quote-price', ['unit_id' => $other['unit']] + $body)->assertNotFound();
});

it('risponde 404 per struttura in bozza, slug sconosciuti e tenant non attivo', function () {
    $this->getJson("/api/v1/public/tenant-inesistente/{$this->propertySlug}")->assertNotFound()->assertJsonPath('error.code', 'NOT_FOUND');
    $this->getJson("/api/v1/public/{$this->tenantSlug}/struttura-inesistente")->assertNotFound();

    // Lo stesso slug di struttura in un altro tenant non Ã¨ raggiungibile da questo tenant.
    $otherSlug = 'tenant-altro-'.substr(uuid7(), -6);
    $other = createTenantRow($otherSlug);
    $draft = createPropertyRow($other, 'solo-altro');
    $this->getJson("/api/v1/public/{$this->tenantSlug}/solo-altro")->assertNotFound();
    $this->getJson("/api/v1/public/{$otherSlug}/solo-altro")->assertNotFound();

    inTenant($this->tenant, fn () => DB::table('properties')->where('id', $this->golden['property'])->update(['status' => 'DRAFT']));
    $this->getJson($this->base)->assertNotFound();
    $this->postJson($this->base.'/quote-price', ['unit_id' => $this->golden['unit'], 'check_in' => '2027-09-10', 'check_out' => '2027-09-13', 'adults' => 2])
        ->assertNotFound();

    inTenant($this->tenant, function () {
        DB::table('properties')->where('id', $this->golden['property'])->update(['status' => 'PUBLISHED']);
        DB::table('tenants')->where('id', $this->tenant)->update(['status' => 'SUSPENDED']);
    });
    $this->getJson($this->base)->assertNotFound();

    expect($draft)->toBeString();
});

it('limita le letture pubbliche a 120 al minuto per IP', function () {
    config(['security.public_read_rate_limit_per_minute' => 2]);

    $this->getJson($this->base)->assertOk();
    $this->getJson($this->base)->assertOk();
    $this->getJson($this->base)->assertStatus(429)->assertJsonPath('error.code', 'RATE_LIMITED');
});
