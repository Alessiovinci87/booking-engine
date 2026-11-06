<?php

use App\Domain\Tenancy\Models\AuditLog;
use Illuminate\Support\Facades\DB;

/*
 * 2.6 (AC1, parte pannello): POST /admin/properties/{id}/publish con le guardie di 07 nell'ordine
 * unità attiva, traduzione it, politica di cancellazione predefinita (ADR-0009), Stripe charges_enabled.
 * Lo stato della struttura cambia solo tramite PropertySetupService.
 */

beforeEach(function () {
    $this->owner = signedInOwner($this);
    $this->tenant = $this->owner['tenant'];

    $this->property = $this->postJson('/api/v1/admin/properties', [
        'name' => 'Casa da pubblicare',
        'slug' => 'casa-da-pubblicare',
    ])->assertCreated()->json();

    $this->publishUrl = "/api/v1/admin/properties/{$this->property['id']}/publish";
});

function publishReady(string $tenant, array $property, bool $chargesEnabled = true): void
{
    $unit = createUnitRow($tenant, $property['id']);

    inTenant($tenant, function () use ($tenant, $property, $chargesEnabled, $unit) {
        DB::table('property_translations')->insert([
            'id' => uuid7(), 'tenant_id' => $tenant, 'property_id' => $property['id'], 'locale' => 'it',
            'name' => 'Casa da pubblicare', 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('properties')->where('id', $property['id'])->update([
            'default_cancellation_policy_id' => $property['cancellation_policies'][0]['id'],
            'privacy_policy_url' => 'https://example.test/privacy',
        ]);
        DB::table('stripe_accounts')->insert([
            'id' => uuid7(), 'tenant_id' => $tenant, 'stripe_account_id' => 'acct_test_'.substr($unit, -12),
            'charges_enabled' => $chargesEnabled, 'created_at' => now(), 'updated_at' => now(),
        ]);
    });
}

it('rifiuta la pubblicazione elencando tutte le guardie mancanti', function () {
    panelPost($this, $this->publishUrl)
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'NO_ACTIVE_UNITS')
        ->assertJsonPath('error.details.missing', [
            'NO_ACTIVE_UNITS', 'TRANSLATION_IT_MISSING', 'CANCELLATION_POLICY_MISSING', 'PRIVACY_POLICY_MISSING', 'PAYMENTS_NOT_ENABLED',
        ]);

    expect(inTenant($this->tenant, fn () => DB::table('properties')->where('id', $this->property['id'])->value('status')))->toBe('DRAFT');
});

it('pubblica la struttura quando tutte le guardie sono soddisfatte, una volta sola', function () {
    publishReady($this->tenant, $this->property);

    panelPost($this, $this->publishUrl)
        ->assertOk()
        ->assertJsonPath('status', 'PUBLISHED')
        ->assertHeader('ETag', '"2"');

    panelPost($this, $this->publishUrl)
        ->assertOk()
        ->assertJsonPath('status', 'PUBLISHED')
        ->assertJsonPath('version', 2);

    $audits = inTenant($this->tenant, fn () => AuditLog::query()->where('action', 'property.published')->where('subject_id', $this->property['id'])->count());
    expect($audits)->toBe(1);
});

it('non conta le unità disattivate', function () {
    publishReady($this->tenant, $this->property);
    inTenant($this->tenant, fn () => DB::table('units')->where('property_id', $this->property['id'])->update(['status' => 'INACTIVE']));

    panelPost($this, $this->publishUrl)
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'NO_ACTIVE_UNITS')
        ->assertJsonPath('error.details.missing', ['NO_ACTIVE_UNITS']);
});

it('richiede la traduzione italiana', function () {
    publishReady($this->tenant, $this->property);
    inTenant($this->tenant, fn () => DB::table('property_translations')->where('property_id', $this->property['id'])->update(['locale' => 'en']));

    panelPost($this, $this->publishUrl)
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'TRANSLATION_IT_MISSING')
        ->assertJsonPath('error.details.missing', ['TRANSLATION_IT_MISSING']);
});

it('richiede la politica di cancellazione predefinita (ADR-0009)', function () {
    publishReady($this->tenant, $this->property);
    inTenant($this->tenant, fn () => DB::table('properties')->where('id', $this->property['id'])->update(['default_cancellation_policy_id' => null]));

    panelPost($this, $this->publishUrl)
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'CANCELLATION_POLICY_MISSING')
        ->assertJsonPath('error.details.missing', ['CANCELLATION_POLICY_MISSING'])
        ->assertJsonPath('error.message', 'Manca la politica di cancellazione predefinita.');
});

it('richiede un account Stripe abilitato agli incassi', function () {
    publishReady($this->tenant, $this->property, chargesEnabled: false);

    panelPost($this, $this->publishUrl)
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'PAYMENTS_NOT_ENABLED')
        ->assertJsonPath('error.details.missing', ['PAYMENTS_NOT_ENABLED']);
});

it('non usa l\'account Stripe di un altro tenant', function () {
    publishReady($this->tenant, $this->property, chargesEnabled: false);

    $other = createTenantRow();
    inTenant($other, fn () => DB::table('stripe_accounts')->insert([
        'id' => uuid7(), 'tenant_id' => $other, 'stripe_account_id' => 'acct_test_altro_'.substr($other, -8),
        'charges_enabled' => true, 'created_at' => now(), 'updated_at' => now(),
    ]));

    panelPost($this, $this->publishUrl)->assertStatus(422)->assertJsonPath('error.code', 'PAYMENTS_NOT_ENABLED');
});

it('rifiuta la pubblicazione di una struttura archiviata', function () {
    publishReady($this->tenant, $this->property);
    inTenant($this->tenant, fn () => DB::table('properties')->where('id', $this->property['id'])->update(['status' => 'ARCHIVED']));

    panelPost($this, $this->publishUrl)
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'ILLEGAL_TRANSITION');
});

it('risponde 404 per la struttura di un altro tenant', function () {
    $foreign = twoTenantsWithUnits()['b'];

    panelPost($this, "/api/v1/admin/properties/{$foreign['property']}/publish")
        ->assertNotFound()
        ->assertJsonPath('error.code', 'NOT_FOUND');

    expect(inTenant($foreign['tenant'], fn () => DB::table('properties')->where('id', $foreign['property'])->value('status')))->toBe('DRAFT');
});

it('impedisce di togliere a una struttura pubblicata ciò che la pubblicazione richiede', function () {
    publishReady($this->tenant, $this->property);
    $version = panelPost($this, $this->publishUrl)->assertOk()->json('version');

    panelPatch($this, "/api/v1/admin/properties/{$this->property['id']}", ['default_cancellation_policy_id' => null], $version)
        ->assertStatus(400)
        ->assertJsonStructure(['error' => ['details' => ['fields' => ['default_cancellation_policy_id']]]]);

    panelPatch($this, "/api/v1/admin/properties/{$this->property['id']}", ['translations' => ['it' => null]], $version)
        ->assertStatus(400)
        ->assertJsonStructure(['error' => ['details' => ['fields' => ['translations.it']]]]);
});

it('richiede la Idempotency-Key e restituisce la stessa risposta per la stessa chiave (I9)', function () {
    publishReady($this->tenant, $this->property);

    $this->withHeaders(['Referer' => TEST_PANEL_ORIGIN.'/app'])->postJson($this->publishUrl)
        ->assertStatus(400)
        ->assertJsonPath('error.code', 'VALIDATION_FAILED');

    $key = uuid7();
    $first = panelPost($this, $this->publishUrl, [], $key)->assertOk();
    panelPost($this, $this->publishUrl, [], $key)->assertOk()->assertHeader('Idempotent-Replayed', 'true');
    panelPost($this, $this->publishUrl, ['x' => 1], $key)->assertStatus(422)->assertJsonPath('error.code', 'IDEMPOTENCY_KEY_REUSED');

    expect($first->json('status'))->toBe('PUBLISHED')
        ->and(inTenant($this->tenant, fn () => AuditLog::query()->where('action', 'property.published')->count()))->toBe(1);
});

it('richiede il link all\'informativa privacy e non lo lascia togliere dopo la pubblicazione (3.7)', function () {
    publishReady($this->tenant, $this->property);
    inTenant($this->tenant, fn () => DB::table('properties')->where('id', $this->property['id'])->update(['privacy_policy_url' => null]));

    panelPost($this, $this->publishUrl)
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'PRIVACY_POLICY_MISSING')
        ->assertJsonPath('error.details.missing', ['PRIVACY_POLICY_MISSING']);

    inTenant($this->tenant, fn () => DB::table('properties')->where('id', $this->property['id'])->update(['privacy_policy_url' => 'https://example.test/privacy']));
    panelPost($this, $this->publishUrl)->assertOk();

    panelPatch($this, "/api/v1/admin/properties/{$this->property['id']}", ['privacy_policy_url' => null], 2)
        ->assertStatus(400)
        ->assertJsonStructure(['error' => ['details' => ['fields' => ['privacy_policy_url']]]]);
});
