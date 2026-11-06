<?php

use App\Domain\Tenancy\Models\AuditLog;
use Illuminate\Support\Facades\DB;

/*
 * 2.1: API admin delle strutture (07). Traduzioni { it, en }, If-Match con 412, risorse di altri
 * tenant → 404, errori nel formato di 07.
 */

function propertyPayload(array $overrides = []): array
{
    return array_replace_recursive([
        'name' => 'Casa Prova',
        'slug' => 'casa-prova-api',
        'city' => 'Alghero',
        'checkin_time' => '15:00',
        'checkout_time' => '10:00',
        'translations' => [
            'it' => ['name' => 'Casa Prova', 'description' => 'Descrizione italiana'],
        ],
    ], $overrides);
}

it('crea una struttura con le traduzioni e le politiche standard', function () {
    $owner = signedInOwner($this);

    $response = $this->postJson('/api/v1/admin/properties', propertyPayload())
        ->assertCreated()
        ->assertHeader('ETag', '"1"')
        ->assertJsonPath('slug', 'casa-prova-api')
        ->assertJsonPath('status', 'DRAFT')
        ->assertJsonPath('checkin_time', '15:00')
        ->assertJsonPath('version', 1)
        ->assertJsonPath('translations.it.name', 'Casa Prova')
        ->assertJsonPath('translations.en', null)
        ->assertJsonPath('default_cancellation_policy_id', null)
        ->assertJsonCount(2, 'cancellation_policies');

    $id = $response->json('id');
    $row = inTenant($owner['tenant'], fn () => DB::table('properties')->where('id', $id)->first());
    expect($row->tenant_id)->toBe($owner['tenant']);

    $audit = inTenant($owner['tenant'], fn () => AuditLog::query()->where('action', 'property.created')->where('subject_id', $id)->first());
    expect($audit)->not->toBeNull();
});

it('ignora tenant_id e status inviati dal client', function () {
    $owner = signedInOwner($this);
    $other = createTenantRow();

    $id = $this->postJson('/api/v1/admin/properties', propertyPayload(['tenant_id' => $other, 'status' => 'PUBLISHED']))
        ->assertCreated()
        ->assertJsonPath('status', 'DRAFT')
        ->json('id');

    expect(inTenant($owner['tenant'], fn () => DB::table('properties')->where('id', $id)->value('tenant_id')))->toBe($owner['tenant']);
});

it('elenca solo le strutture del proprio tenant', function () {
    $tenants = twoTenantsWithUnits();
    signedInOwner($this, $tenants['a']['tenant']);

    $this->getJson('/api/v1/admin/properties')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $tenants['a']['property'])
        ->assertJsonPath('next_cursor', null);
});

it('risponde 404 a GET e PATCH di una struttura di un altro tenant', function () {
    $tenants = twoTenantsWithUnits();
    signedInOwner($this, $tenants['a']['tenant']);
    $foreign = '/api/v1/admin/properties/'.$tenants['b']['property'];

    $this->getJson($foreign)->assertNotFound()->assertJsonPath('error.code', 'NOT_FOUND');
    panelPatch($this, $foreign, ['name' => 'Rubata'], 1)->assertNotFound()->assertJsonPath('error.code', 'NOT_FOUND');

    expect(inTenant($tenants['b']['tenant'], fn () => DB::table('properties')->where('id', $tenants['b']['property'])->value('name')))->toBe('Casa di prova');
});

it('risponde 404 a un identificativo che non è un UUID', function () {
    signedInOwner($this);

    $this->getJson('/api/v1/admin/properties/non-un-uuid')->assertNotFound()->assertJsonPath('error.code', 'NOT_FOUND');
});

it('modifica campi e traduzioni con If-Match e incrementa la versione', function () {
    signedInOwner($this);
    $id = $this->postJson('/api/v1/admin/properties', propertyPayload())->json('id');

    panelPatch($this, "/api/v1/admin/properties/{$id}", [
        'cin_code' => 'IT090003C2000000001',
        'translations' => ['en' => ['name' => 'Test House', 'description' => 'English text']],
    ], 1)
        ->assertOk()
        ->assertHeader('ETag', '"2"')
        ->assertJsonPath('version', 2)
        ->assertJsonPath('cin_code', 'IT090003C2000000001')
        ->assertJsonPath('translations.it.name', 'Casa Prova')
        ->assertJsonPath('translations.en.name', 'Test House');

    // Solo traduzioni: la versione cresce comunque.
    panelPatch($this, "/api/v1/admin/properties/{$id}", ['translations' => ['en' => null]], 2)
        ->assertOk()
        ->assertJsonPath('version', 3)
        ->assertJsonPath('translations.en', null);
});

it('risponde 412 VERSION_MISMATCH con versione diversa o senza If-Match', function (?int $version) {
    signedInOwner($this);
    $id = $this->postJson('/api/v1/admin/properties', propertyPayload())->json('id');

    panelPatch($this, "/api/v1/admin/properties/{$id}", ['name' => 'Nuovo nome'], $version)
        ->assertStatus(412)
        ->assertJsonPath('error.code', 'VERSION_MISMATCH')
        ->assertJsonPath('error.details.current_version', 1);

    $this->getJson("/api/v1/admin/properties/{$id}")->assertJsonPath('name', 'Casa Prova');
})->with([
    'versione vecchia' => [7],
    'senza header' => [null],
]);

it('sceglie la politica predefinita solo tra quelle della struttura', function () {
    signedInOwner($this);
    $first = $this->postJson('/api/v1/admin/properties', propertyPayload())->json();
    $second = $this->postJson('/api/v1/admin/properties', propertyPayload(['slug' => 'altra-casa']))->json();
    $url = '/api/v1/admin/properties/'.$first['id'];

    panelPatch($this, $url, ['default_cancellation_policy_id' => $second['cancellation_policies'][0]['id']], 1)
        ->assertStatus(400)
        ->assertJsonPath('error.code', 'VALIDATION_FAILED')
        ->assertJsonStructure(['error' => ['details' => ['fields' => ['default_cancellation_policy_id']]]]);

    $own = $first['cancellation_policies'][0]['id'];
    panelPatch($this, $url, ['default_cancellation_policy_id' => $own], 1)
        ->assertOk()
        ->assertJsonPath('default_cancellation_policy_id', $own);
});

it('valida i campi della struttura', function (array $payload, string $field) {
    signedInOwner($this);

    $this->postJson('/api/v1/admin/properties', $payload)
        ->assertStatus(400)
        ->assertJsonPath('error.code', 'VALIDATION_FAILED')
        ->assertJsonStructure(['error' => ['details' => ['fields' => [$field]]]]);
})->with([
    'nome mancante' => [['slug' => 'casa'], 'name'],
    'slug non valido' => [['name' => 'Casa', 'slug' => 'Casa Bella'], 'slug'],
    'orario non valido' => [['name' => 'Casa', 'slug' => 'casa', 'checkin_time' => '25:00'], 'checkin_time'],
    'url non https' => [['name' => 'Casa', 'slug' => 'casa', 'terms_url' => 'http://example.test/t'], 'terms_url'],
    'commissione fuori intervallo' => [['name' => 'Casa', 'slug' => 'casa', 'ota_commission_bps' => 10000], 'ota_commission_bps'],
    'lingua non prevista' => [['name' => 'Casa', 'slug' => 'casa', 'translations' => ['de' => ['name' => 'Haus']]], 'translations'],
    'traduzione senza nome' => [['name' => 'Casa', 'slug' => 'casa', 'translations' => ['it' => ['description' => 'x']]], 'translations.it.name'],
    'fuso orario non valido' => [['name' => 'Casa', 'slug' => 'casa', 'timezone' => 'Marte/Base'], 'timezone'],
]);

it('rifiuta uno slug già usato nello stesso tenant ma lo consente in un altro', function () {
    $tenants = twoTenantsWithUnits();
    signedInOwner($this, $tenants['a']['tenant']);

    // casa-prova esiste già nel tenant A (fixture) e nel tenant B.
    $this->postJson('/api/v1/admin/properties', propertyPayload(['slug' => 'casa-prova']))
        ->assertStatus(400)
        ->assertJsonStructure(['error' => ['details' => ['fields' => ['slug']]]]);

    $this->postJson('/api/v1/admin/properties', propertyPayload(['slug' => 'casa-prova-2']))->assertCreated();
});

it('richiede la MFA verificata nella sessione', function () {
    $owner = panelUser();

    actingAsPanelUser($this, $owner['user'], mfaVerified: false)
        ->getJson('/api/v1/admin/properties')
        ->assertUnauthorized()
        ->assertJsonPath('error.code', 'MFA_REQUIRED');
});
