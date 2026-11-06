<?php

use App\Domain\Tenancy\Models\User;
use Illuminate\Support\Facades\DB;

/*
 * Accesso al pannello (08): email e password, poi MFA obbligatoria prima di ogni API admin.
 */

it('accetta credenziali valide e richiede poi la MFA', function () {
    $p = panelUser();

    panelPost($this, '/api/v1/admin/auth/login', ['email' => strtoupper($p['user']->email), 'password' => TEST_PANEL_PASSWORD])
        ->assertOk()
        ->assertJson(['mfa_setup_required' => false]);

    $this->assertAuthenticatedAs($p['user'], 'web');

    panelGet($this, '/api/v1/admin/me')
        ->assertUnauthorized()
        ->assertJsonPath('error.code', 'MFA_REQUIRED');

    panelGet($this, '/api/v1/admin/auth/session')
        ->assertOk()
        ->assertJson(['mfa_enabled' => true, 'mfa_verified' => false]);
});

it('segnala la configurazione MFA obbligatoria al primo accesso', function () {
    $p = panelUser(mfa: false);

    panelPost($this, '/api/v1/admin/auth/login', ['email' => $p['user']->email, 'password' => TEST_PANEL_PASSWORD])
        ->assertOk()
        ->assertJson(['mfa_setup_required' => true]);

    panelGet($this, '/api/v1/admin/me')
        ->assertUnauthorized()
        ->assertJsonPath('error.code', 'MFA_SETUP_REQUIRED');
});

it('rifiuta password errata ed email sconosciuta con lo stesso errore', function () {
    $p = panelUser();

    panelPost($this, '/api/v1/admin/auth/login', ['email' => $p['user']->email, 'password' => 'sbagliata-sbagliata'])
        ->assertUnauthorized()
        ->assertJsonPath('error.code', 'INVALID_CREDENTIALS');

    panelPost($this, '/api/v1/admin/auth/login', ['email' => 'nessuno@example.test', 'password' => 'sbagliata-sbagliata'])
        ->assertUnauthorized()
        ->assertJsonPath('error.code', 'INVALID_CREDENTIALS');

    $this->assertGuest('web');
});

it('valida i campi con VALIDATION_FAILED', function () {
    panelPost($this, '/api/v1/admin/auth/login', ['email' => ''])
        ->assertStatus(400)
        ->assertJsonPath('error.code', 'VALIDATION_FAILED');
});

it('registra accessi e fallimenti nell\'audit senza email né password', function () {
    $p = panelUser();

    panelPost($this, '/api/v1/admin/auth/login', ['email' => $p['user']->email, 'password' => 'sbagliata-sbagliata']);
    panelPost($this, '/api/v1/admin/auth/login', ['email' => $p['user']->email, 'password' => TEST_PANEL_PASSWORD]);

    $rows = inTenant($p['tenant'], fn () => DB::table('audit_log')->orderBy('id')->get());

    expect($rows->pluck('action')->all())->toContain('auth.login.failed', 'auth.login.succeeded');

    $dump = json_encode($rows->all());
    expect($dump)->not->toContain($p['user']->email)
        ->and($dump)->not->toContain(TEST_PANEL_PASSWORD)
        ->and($dump)->not->toContain('sbagliata-sbagliata');
});

it('salva le password con Argon2id', function () {
    $p = panelUser();

    expect(DB::table('users')->where('id', $p['user']->id)->value('password_hash'))->toStartWith('$argon2id$');
});

it('esce e invalida la sessione', function () {
    $p = panelUser();

    actingAsPanelUser($this, $p['user'])->postJson('/api/v1/admin/auth/logout')->assertNoContent();

    $this->assertGuest('web');
    expect(User::query()->find($p['user']->id))->not->toBeNull();
});
