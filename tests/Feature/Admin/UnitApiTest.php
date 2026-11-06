<?php

use Illuminate\Support\Facades\DB;

/*
 * 2.1: API admin delle unità (07). Importi in centesimi, traduzioni { it, en }, If-Match, 404 per
 * risorse di altri tenant, politica di cancellazione solo tra quelle della stessa struttura.
 */

function unitPayload(array $overrides = []): array
{
    return array_replace_recursive([
        'name' => 'Trilocale',
        'base_occupancy' => 4,
        'max_occupancy' => 6,
        'min_nights' => 3,
        'cleaning_fee_cents' => 6000,
        'extra_guest_fee_cents' => 1500,
        'base_nightly_cents' => 12000,
        'translations' => ['it' => ['name' => 'Trilocale', 'description' => 'Con giardino']],
    ], $overrides);
}

function propertyWithPolicies(string $tenant): array
{
    $property = createPropertyRow($tenant, 'casa-'.substr(uuid7(), -8));
    $policies = inTenant($tenant, function () use ($tenant, $property) {
        $ids = [];
        foreach (['FLEXIBLE_7D', 'NON_REFUNDABLE'] as $code) {
            $ids[$code] = uuid7();
            DB::table('cancellation_policies')->insert([
                'id' => $ids[$code], 'tenant_id' => $tenant, 'property_id' => $property, 'code' => $code,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        return $ids;
    });

    return ['property' => $property, 'policies' => $policies];
}

it('crea un\'unità con il piano tariffario attivo', function () {
    $owner = signedInOwner($this);
    $property = createPropertyRow($owner['tenant']);

    $id = $this->postJson("/api/v1/admin/properties/{$property}/units", unitPayload())
        ->assertCreated()
        ->assertHeader('ETag', '"1"')
        ->assertJsonPath('property_id', $property)
        ->assertJsonPath('booking_mode', 'REQUEST')
        ->assertJsonPath('payment_mode', 'DEPOSIT_30')
        ->assertJsonPath('cleaning_fee', ['amount_cents' => 6000, 'currency' => 'EUR'])
        ->assertJsonPath('extra_guest_fee.amount_cents', 1500)
        ->assertJsonPath('sales_suspended', false)
        ->assertJsonPath('status', 'ACTIVE')
        ->assertJsonPath('translations.it.description', 'Con giardino')
        ->json('id');

    $plan = inTenant($owner['tenant'], fn () => DB::table('rate_plans')->where('unit_id', $id)->first());
    expect($plan->active)->toBeTrue()
        ->and((int) $plan->base_nightly_cents)->toBe(12000)
        ->and($plan->currency)->toBe('EUR');
});

it('elenca le unità della struttura', function () {
    $tenants = twoTenantsWithUnits();
    signedInOwner($this, $tenants['a']['tenant']);

    $this->getJson("/api/v1/admin/properties/{$tenants['a']['property']}/units")
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $tenants['a']['unit']);
});

it('risponde 404 per unità e strutture di un altro tenant', function () {
    $tenants = twoTenantsWithUnits();
    signedInOwner($this, $tenants['a']['tenant']);
    $b = $tenants['b'];

    $this->getJson("/api/v1/admin/units/{$b['unit']}")->assertNotFound()->assertJsonPath('error.code', 'NOT_FOUND');
    panelPatch($this, "/api/v1/admin/units/{$b['unit']}", ['name' => 'X'], 1)->assertNotFound();
    $this->getJson("/api/v1/admin/properties/{$b['property']}/units")->assertNotFound();
    $this->postJson("/api/v1/admin/properties/{$b['property']}/units", unitPayload())->assertNotFound();

    expect(inTenant($b['tenant'], fn () => DB::table('units')->where('property_id', $b['property'])->count()))->toBe(1);
});

it('modifica l\'unità con If-Match', function () {
    $tenants = twoTenantsWithUnits();
    signedInOwner($this, $tenants['a']['tenant']);
    $url = "/api/v1/admin/units/{$tenants['a']['unit']}";

    panelPatch($this, $url, ['min_nights' => 4, 'payment_mode' => 'FULL', 'translations' => ['en' => ['name' => 'Flat']]], 1)
        ->assertOk()
        ->assertHeader('ETag', '"2"')
        ->assertJsonPath('min_nights', 4)
        ->assertJsonPath('payment_mode', 'FULL')
        ->assertJsonPath('translations.en.name', 'Flat');

    panelPatch($this, $url, ['min_nights' => 5], 1)
        ->assertStatus(412)
        ->assertJsonPath('error.code', 'VERSION_MISMATCH')
        ->assertJsonPath('error.details.current_version', 2);
});

it('accetta solo le politiche di cancellazione della stessa struttura', function () {
    $owner = signedInOwner($this);
    $mine = propertyWithPolicies($owner['tenant']);
    $other = propertyWithPolicies($owner['tenant']);
    $unit = createUnitRow($owner['tenant'], $mine['property']);

    panelPatch($this, "/api/v1/admin/units/{$unit}", ['cancellation_policy_id' => $other['policies']['NON_REFUNDABLE']], 1)
        ->assertStatus(400)
        ->assertJsonStructure(['error' => ['details' => ['fields' => ['cancellation_policy_id']]]]);

    panelPatch($this, "/api/v1/admin/units/{$unit}", ['cancellation_policy_id' => $mine['policies']['NON_REFUNDABLE']], 1)
        ->assertOk()
        ->assertJsonPath('cancellation_policy_id', $mine['policies']['NON_REFUNDABLE']);

    panelPatch($this, "/api/v1/admin/units/{$unit}", ['cancellation_policy_id' => null], 2)
        ->assertOk()
        ->assertJsonPath('cancellation_policy_id', null);
});

it('valida i campi dell\'unità', function (array $overrides, string $field) {
    $owner = signedInOwner($this);
    $property = createPropertyRow($owner['tenant']);

    $this->postJson("/api/v1/admin/properties/{$property}/units", unitPayload($overrides))
        ->assertStatus(400)
        ->assertJsonPath('error.code', 'VALIDATION_FAILED')
        ->assertJsonStructure(['error' => ['details' => ['fields' => [$field]]]]);
})->with([
    'capienza massima sotto la base' => [['base_occupancy' => 4, 'max_occupancy' => 3], 'max_occupancy'],
    'importo non intero' => [['cleaning_fee_cents' => 60.5], 'cleaning_fee_cents'],
    'importo negativo' => [['extra_guest_fee_cents' => -1], 'extra_guest_fee_cents'],
    'prezzo base zero' => [['base_nightly_cents' => 0], 'base_nightly_cents'],
    'minimo notti zero' => [['min_nights' => 0], 'min_nights'],
    'modalità non prevista' => [['booking_mode' => 'AUTO'], 'booking_mode'],
    'pagamento non previsto' => [['payment_mode' => 'HALF'], 'payment_mode'],
]);

it('rifiuta in modifica una capienza massima sotto quella base', function () {
    $tenants = twoTenantsWithUnits();
    signedInOwner($this, $tenants['a']['tenant']);

    // Fixture: base 2, massima 4.
    panelPatch($this, "/api/v1/admin/units/{$tenants['a']['unit']}", ['max_occupancy' => 1], 1)
        ->assertStatus(400)
        ->assertJsonStructure(['error' => ['details' => ['fields' => ['max_occupancy']]]]);
});
