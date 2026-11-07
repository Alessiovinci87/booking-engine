<?php

use App\Domain\Tenancy\Models\AuditLog;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/*
 * 6.10 — Strumenti di piattaforma per il pilota:
 * - tenant:embed-origins: domini della vista embed, solo https senza percorso, con audit;
 * - user:reset-mfa: azzera MFA e codici di recupero, chiude le sessioni, obbliga a riconfigurare la
 *   MFA al prossimo accesso; audit con ID e motivazione, mai l'email.
 */

beforeEach(function () {
    Http::fake(['api.pwnedpasswords.com/*' => Http::response('', 200)]);
    $this->owner = panelUser();
    $this->slug = inTenant($this->owner['tenant'], fn () => DB::table('tenants')->value('slug'));
});

afterEach(fn () => Carbon::setTestNow());

function embedOrigins(string $tenant): array
{
    return inTenant($tenant, fn () => Tenant::query()->firstOrFail()->embed_allowed_origins);
}

it('embed: aggiunge origin normalizzati, li elenca, li toglie; ogni modifica va in audit', function () {
    $this->artisan('tenant:embed-origins', ['slug' => $this->slug, '--add' => ['https://WWW.Casa-Esempio.it/', 'https://prenota.example.com:8443']])
        ->expectsOutput('https://prenota.example.com:8443')
        ->expectsOutput('https://www.casa-esempio.it')
        ->assertSuccessful();

    expect(embedOrigins($this->owner['tenant']))->toBe(['https://prenota.example.com:8443', 'https://www.casa-esempio.it']);

    // Ripetere un'aggiunta non cambia nulla e non scrive l'audit.
    $this->artisan('tenant:embed-origins', ['slug' => $this->slug, '--add' => ['https://www.casa-esempio.it']])->assertSuccessful();
    $this->artisan('tenant:embed-origins', ['slug' => $this->slug, '--list' => true])->expectsOutput('https://www.casa-esempio.it')->assertSuccessful();
    $this->artisan('tenant:embed-origins', ['slug' => $this->slug, '--remove' => ['https://prenota.example.com:8443']])->assertSuccessful();

    expect(embedOrigins($this->owner['tenant']))->toBe(['https://www.casa-esempio.it']);

    $audit = inTenant($this->owner['tenant'], fn () => AuditLog::query()->where('action', 'tenant.embed_origins_updated')->get());
    $removal = $audit->first(fn (AuditLog $row) => $row->changes['embed_allowed_origins']['to'] === ['https://www.casa-esempio.it']);
    expect($audit)->toHaveCount(2)
        ->and($removal?->changes['embed_allowed_origins']['from'])->toBe(['https://prenota.example.com:8443', 'https://www.casa-esempio.it'])
        ->and($removal?->actor_type->value)->toBe('SYSTEM');
});

it('embed: rifiuta origin non https, con percorso, query, credenziali, IP o senza dominio', function (string $origin) {
    $this->artisan('tenant:embed-origins', ['slug' => $this->slug, '--add' => [$origin]])->assertFailed();
    expect(embedOrigins($this->owner['tenant']))->toBe([]);
})->with([
    'http' => 'http://www.casa-esempio.it',
    'percorso' => 'https://www.casa-esempio.it/prenota',
    'query' => 'https://www.casa-esempio.it?x=1',
    'frammento' => 'https://www.casa-esempio.it#top',
    'credenziali' => 'https://utente:segreto@www.casa-esempio.it',
    'ip' => 'https://203.0.113.9',
    'localhost' => 'https://localhost',
    'testo' => 'casa-esempio.it',
]);

it('embed: tenant sconosciuto o nessuna opzione → errore', function () {
    $this->artisan('tenant:embed-origins', ['slug' => 'inesistente', '--list' => true])->assertFailed();
    $this->artisan('tenant:embed-origins', ['slug' => $this->slug])->assertFailed();
});

it('reset MFA: promemoria, conferma, segreto e codici cancellati, audit con ID e motivazione', function () {
    $user = $this->owner['user'];
    DB::table('user_recovery_codes')->insert(['id' => uuid7(), 'user_id' => $user->id, 'code_hash' => hash('sha256', 'x'), 'created_at' => now(), 'updated_at' => now()]);

    $this->artisan('user:reset-mfa', ['email' => strtoupper($user->email), '--reason' => 'Telefono perso, identità verificata per telefono'])
        ->expectsOutput('Verifica l\'identità del gestore per telefono prima di eseguire questo comando.')
        ->expectsConfirmation('Hai verificato l\'identità per telefono e vuoi azzerare MFA e codici di recupero?', 'yes')
        ->assertSuccessful();

    $fresh = User::query()->findOrFail($user->id);
    expect($fresh->mfa_secret_encrypted)->toBeNull()
        ->and($fresh->mfa_enabled_at)->toBeNull()
        ->and($fresh->sessions_revoked_at)->not->toBeNull()
        ->and(DB::table('user_recovery_codes')->where('user_id', $user->id)->count())->toBe(0);

    $audit = inTenant($this->owner['tenant'], fn () => AuditLog::query()->where('action', 'user.mfa_reset')->sole());
    expect($audit->subject_id)->toBe($user->id)
        ->and($audit->changes)->toBe(['reason' => 'Telefono perso, identità verificata per telefono'])
        ->and(json_encode($audit->getAttributes()))->not->toContain($user->email);

    // Al prossimo accesso la MFA va configurata di nuovo.
    panelPost($this, '/api/v1/admin/auth/login', ['email' => $user->email, 'password' => TEST_PANEL_PASSWORD])
        ->assertOk()->assertJson(['mfa_setup_required' => true]);
});

it('reset MFA: motivazione mancante o con dati personali, utente sconosciuto, conferma negata → nessuna modifica', function () {
    $user = $this->owner['user'];

    $this->artisan('user:reset-mfa', ['email' => $user->email])->assertFailed();
    $this->artisan('user:reset-mfa', ['email' => $user->email, '--reason' => 'breve'])->assertFailed();
    $this->artisan('user:reset-mfa', ['email' => $user->email, '--reason' => 'Richiesta da mario.rossi@example.test'])->assertFailed();
    $this->artisan('user:reset-mfa', ['email' => $user->email, '--reason' => 'Richiamato al numero 333 123 4567'])->assertFailed();
    $this->artisan('user:reset-mfa', ['email' => 'nessuno@example.test', '--reason' => 'Telefono perso, identità verificata'])->assertFailed();
    $this->artisan('user:reset-mfa', ['email' => $user->email, '--reason' => 'Telefono perso, identità verificata'])
        ->expectsConfirmation('Hai verificato l\'identità per telefono e vuoi azzerare MFA e codici di recupero?', 'no')
        ->assertFailed();

    expect(User::query()->findOrFail($user->id)->mfa_enabled_at)->not->toBeNull()
        ->and(inTenant($this->owner['tenant'], fn () => AuditLog::query()->where('action', 'user.mfa_reset')->count()))->toBe(0);
});

it('reset MFA: le sessioni aperte prima della revoca si chiudono alla richiesta successiva', function () {
    actingAsPanelUser($this, $this->owner['user']);
    panelGet($this, '/api/v1/admin/me')->assertOk();

    Carbon::setTestNow(now()->addMinute());
    // Stessa istanza usata da actingAs: nelle richieste vere l'utente si rilegge dal database.
    $this->owner['user']->forceFill(['sessions_revoked_at' => now()])->save();

    panelGet($this, '/api/v1/admin/me')->assertUnauthorized()->assertJsonPath('error.code', 'UNAUTHENTICATED');
});

it('reset MFA: una sessione iniziata dopo la revoca resta valida', function () {
    User::query()->whereKey($this->owner['user']->id)->update(['sessions_revoked_at' => now()->subMinute()]);
    actingAsPanelUser($this, $this->owner['user']->refresh());

    panelGet($this, '/api/v1/admin/me')->assertOk();
});
