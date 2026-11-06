<?php

use Illuminate\Support\Facades\DB;

/*
 * 2.3: tariffe e stagioni (04, 07). Un piano attivo per unità, CRUD delle stagioni, avviso per le
 * sovrapposizioni a parità di priorità (consentite), If-Match, 404 per altri tenant.
 */

beforeEach(function () {
    $this->tenants = twoTenantsWithUnits();
    $this->a = $this->tenants['a'];
    $this->plan = createRatePlanRow($this->a['tenant'], $this->a['unit']);
    createRatePlanRow($this->tenants['b']['tenant'], $this->tenants['b']['unit']);
    signedInOwner($this, $this->a['tenant']);
    $this->base = "/api/v1/admin/units/{$this->a['unit']}";
});

function season(array $overrides = []): array
{
    return array_merge([
        'name' => 'Agosto',
        'first_night' => '2027-08-01',
        'last_night' => '2027-08-31',
        'nightly_cents' => 18000,
        'priority' => 10,
        'min_nights' => 5,
    ], $overrides);
}

it('legge e modifica il prezzo base con If-Match', function () {
    $this->getJson("{$this->base}/rate-plan")
        ->assertOk()
        ->assertHeader('ETag', '"1"')
        ->assertJsonPath('id', $this->plan)
        ->assertJsonPath('base_nightly', ['amount_cents' => 12000, 'currency' => 'EUR']);

    panelPatch($this, "{$this->base}/rate-plan", ['base_nightly_cents' => 13000], 1)
        ->assertOk()
        ->assertJsonPath('base_nightly.amount_cents', 13000)
        ->assertJsonPath('version', 2);

    panelPatch($this, "{$this->base}/rate-plan", ['base_nightly_cents' => 14000], 1)
        ->assertStatus(412)
        ->assertJsonPath('error.code', 'VERSION_MISMATCH');

    panelPatch($this, "{$this->base}/rate-plan", ['base_nightly_cents' => 0], 2)
        ->assertStatus(400)
        ->assertJsonStructure(['error' => ['details' => ['fields' => ['base_nightly_cents']]]]);
});

it('crea, elenca, modifica ed elimina le stagioni', function () {
    $created = $this->postJson("{$this->base}/seasons", season())
        ->assertCreated()
        ->assertJsonPath('first_night', '2027-08-01')
        ->assertJsonPath('last_night', '2027-08-31')
        ->assertJsonPath('nightly', ['amount_cents' => 18000, 'currency' => 'EUR'])
        ->assertJsonPath('min_nights', 5)
        ->assertJsonPath('warnings', [])
        ->json();

    // Nel database: [prima notte, ultima + 1).
    $range = inTenant($this->a['tenant'], fn () => DB::table('season_rules')->where('id', $created['id'])->value('date_range'));
    expect($range)->toBe('[2027-08-01,2027-09-01)');

    $this->getJson("{$this->base}/seasons")->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('warnings', []);

    panelPatch($this, "{$this->base}/seasons/{$created['id']}", ['last_night' => '2027-08-30', 'min_nights' => null], 1)
        ->assertOk()
        ->assertJsonPath('first_night', '2027-08-01')
        ->assertJsonPath('last_night', '2027-08-30')
        ->assertJsonPath('min_nights', null)
        ->assertJsonPath('version', 2);

    panelPatch($this, "{$this->base}/seasons/{$created['id']}", ['nightly_cents' => 1], 1)->assertStatus(412);

    $this->deleteJson("{$this->base}/seasons/{$created['id']}")->assertNoContent();
    $this->getJson("{$this->base}/seasons")->assertJsonCount(0, 'data');
});

it('accetta una stagione di una sola notte', function () {
    $this->postJson("{$this->base}/seasons", season(['first_night' => '2027-08-15', 'last_night' => '2027-08-15']))
        ->assertCreated()
        ->assertJsonPath('last_night', '2027-08-15');
});

it('consente sovrapposizioni con la stessa priorità ma restituisce un avviso', function () {
    $august = $this->postJson("{$this->base}/seasons", season())->json('id');

    $response = $this->postJson("{$this->base}/seasons", season(['name' => 'Metà agosto', 'first_night' => '2027-08-10', 'last_night' => '2027-08-20']))
        ->assertCreated()
        ->assertJsonCount(1, 'warnings')
        ->assertJsonPath('warnings.0.code', 'SEASON_OVERLAP_SAME_PRIORITY')
        ->assertJsonPath('warnings.0.priority', 10);

    $ids = collect($response->json('warnings.0.seasons'))->pluck('id')->all();
    expect($ids)->toBe([$response->json('id'), $august]);

    $this->getJson("{$this->base}/seasons")
        ->assertJsonCount(2, 'data')
        ->assertJsonCount(1, 'warnings')
        ->assertJsonPath('warnings.0.seasons.0.id', $august);
});

it('non avvisa per priorità diverse o periodi che si toccano senza sovrapporsi', function () {
    $this->postJson("{$this->base}/seasons", season());

    $this->postJson("{$this->base}/seasons", season(['name' => 'Ferragosto', 'first_night' => '2027-08-13', 'last_night' => '2027-08-17', 'priority' => 20]))
        ->assertCreated()->assertJsonPath('warnings', []);
    $this->postJson("{$this->base}/seasons", season(['name' => 'Settembre', 'first_night' => '2027-09-01', 'last_night' => '2027-09-30']))
        ->assertCreated()->assertJsonPath('warnings', []);

    $this->getJson("{$this->base}/seasons")->assertJsonPath('warnings', []);
});

it('valida le stagioni', function (array $overrides, string $field) {
    $this->postJson("{$this->base}/seasons", season($overrides))
        ->assertStatus(400)
        ->assertJsonPath('error.code', 'VALIDATION_FAILED')
        ->assertJsonStructure(['error' => ['details' => ['fields' => [$field]]]]);
})->with([
    'ultima notte prima della prima' => [['last_night' => '2027-07-31'], 'last_night'],
    'data non valida' => [['first_night' => '2027-02-30'], 'first_night'],
    'prezzo zero' => [['nightly_cents' => 0], 'nightly_cents'],
    'prezzo non intero' => [['nightly_cents' => '180.5'], 'nightly_cents'],
    'minimo notti zero' => [['min_nights' => 0], 'min_nights'],
    'priorità negativa' => [['priority' => -1], 'priority'],
]);

it('rifiuta in modifica un\'ultima notte precedente alla prima', function () {
    $id = $this->postJson("{$this->base}/seasons", season())->json('id');

    panelPatch($this, "{$this->base}/seasons/{$id}", ['first_night' => '2027-09-15'], 1)
        ->assertStatus(400)
        ->assertJsonStructure(['error' => ['details' => ['fields' => ['last_night']]]]);
});

it('risponde 404 per tariffe e stagioni di un altro tenant', function () {
    $b = $this->tenants['b'];
    $foreign = "/api/v1/admin/units/{$b['unit']}";

    $this->getJson("{$foreign}/rate-plan")->assertNotFound()->assertJsonPath('error.code', 'NOT_FOUND');
    panelPatch($this, "{$foreign}/rate-plan", ['base_nightly_cents' => 1], 1)->assertNotFound();
    $this->getJson("{$foreign}/seasons")->assertNotFound();
    $this->postJson("{$foreign}/seasons", season())->assertNotFound();

    $seasonB = inTenant($b['tenant'], function () use ($b) {
        $plan = DB::table('rate_plans')->where('unit_id', $b['unit'])->value('id');
        $id = uuid7();
        DB::table('season_rules')->insert([
            'id' => $id, 'tenant_id' => $b['tenant'], 'rate_plan_id' => $plan, 'name' => 'B', 'nightly_cents' => 100,
            'date_range' => '[2027-08-01,2027-08-10)', 'created_at' => now(), 'updated_at' => now(),
        ]);

        return $id;
    });

    // Anche passando la stagione di B sotto l'unità di A.
    panelPatch($this, "{$this->base}/seasons/{$seasonB}", ['nightly_cents' => 1], 1)->assertNotFound();
    $this->deleteJson("{$this->base}/seasons/{$seasonB}")->assertNotFound();
    expect(inTenant($b['tenant'], fn () => DB::table('season_rules')->where('id', $seasonB)->value('nightly_cents')))->toBe(100);
});
