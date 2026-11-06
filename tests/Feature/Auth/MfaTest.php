<?php

use App\Domain\Tenancy\Auth\RecoveryCodes;
use App\Domain\Tenancy\Models\User;
use Illuminate\Support\Facades\DB;

/*
 * MFA TOTP obbligatoria (08): configurazione al primo accesso con QR locale e 10 codici di recupero
 * monouso salvati come hash; verifica a ogni accesso.
 */

it('configura la MFA al primo accesso e restituisce 10 codici di recupero', function () {
    $p = panelUser(mfa: false);
    actingAsPanelUser($this, $p['user'], mfaVerified: false);

    $setup = $this->getJson('/api/v1/admin/auth/mfa/setup')
        ->assertOk()
        ->assertHeader('Cache-Control', 'no-store, private');

    expect($setup->json('qr_svg'))->toContain('<svg');
    $secret = $setup->json('secret');

    $this->postJson('/api/v1/admin/auth/mfa/setup', ['code' => '000000'])
        ->assertUnauthorized()
        ->assertJsonPath('error.code', 'INVALID_MFA_CODE');

    $codes = $this->postJson('/api/v1/admin/auth/mfa/setup', ['code' => currentOtp($secret)])
        ->assertOk()
        ->json('recovery_codes');

    expect($codes)->toHaveCount(10)
        ->and(array_unique($codes))->toHaveCount(10)
        ->and(User::query()->findOrFail($p['user']->id)->hasMfaEnabled())->toBeTrue();

    // Solo hash nel database, mai i codici o il segreto in chiaro.
    $stored = DB::table('user_recovery_codes')->where('user_id', $p['user']->id)->pluck('code_hash')->all();
    expect($stored)->toHaveCount(10)
        ->and($stored)->not->toContain($codes[0])
        ->and($stored)->toContain(RecoveryCodes::hash($codes[0]))
        ->and(DB::table('users')->where('id', $p['user']->id)->value('mfa_secret_encrypted'))->not->toBe($secret);

    // La sessione è già verificata: le API admin sono accessibili.
    $this->getJson('/api/v1/admin/me')->assertOk();
});

it('non permette di ripetere la configurazione con la MFA attiva', function () {
    $p = panelUser();
    actingAsPanelUser($this, $p['user'], mfaVerified: false);

    $this->getJson('/api/v1/admin/auth/mfa/setup')->assertStatus(409)->assertJsonPath('error.code', 'MFA_ALREADY_ENABLED');
    $this->postJson('/api/v1/admin/auth/mfa/setup', ['code' => currentOtp($p['secret'])])->assertStatus(409);
});

it('verifica il codice TOTP e sblocca le API admin', function () {
    $p = panelUser();
    actingAsPanelUser($this, $p['user'], mfaVerified: false);

    $this->getJson('/api/v1/admin/me')->assertUnauthorized()->assertJsonPath('error.code', 'MFA_REQUIRED');

    $this->postJson('/api/v1/admin/auth/mfa/verify', ['code' => currentOtp($p['secret'])])->assertOk();

    $this->getJson('/api/v1/admin/me')->assertOk();
});

it('non accetta due volte lo stesso codice TOTP', function () {
    $p = panelUser();
    $code = currentOtp($p['secret']);

    actingAsPanelUser($this, $p['user'], mfaVerified: false);
    $this->postJson('/api/v1/admin/auth/mfa/verify', ['code' => $code])->assertOk();

    actingAsPanelUser($this, $p['user'], mfaVerified: false);
    $this->postJson('/api/v1/admin/auth/mfa/verify', ['code' => $code])
        ->assertUnauthorized()
        ->assertJsonPath('error.code', 'INVALID_MFA_CODE');
});

it('accetta un codice di recupero una sola volta', function () {
    $p = panelUser();
    $codes = app(RecoveryCodes::class)->regenerate($p['user']);

    actingAsPanelUser($this, $p['user'], mfaVerified: false);
    $this->postJson('/api/v1/admin/auth/mfa/verify', ['recovery_code' => strtoupper($codes[3])])
        ->assertOk()
        ->assertJson(['recovery_codes_remaining' => 9]);

    actingAsPanelUser($this, $p['user'], mfaVerified: false);
    $this->postJson('/api/v1/admin/auth/mfa/verify', ['recovery_code' => $codes[3]])
        ->assertUnauthorized()
        ->assertJsonPath('error.code', 'INVALID_MFA_CODE');
});

it('richiede la configurazione prima della verifica', function () {
    $p = panelUser(mfa: false);
    actingAsPanelUser($this, $p['user'], mfaVerified: false);

    $this->postJson('/api/v1/admin/auth/mfa/verify', ['code' => '123456'])
        ->assertUnauthorized()
        ->assertJsonPath('error.code', 'MFA_SETUP_REQUIRED');
});

it('il flusso completo login + MFA apre il pannello', function () {
    $p = panelUser();

    panelPost($this, '/api/v1/admin/auth/login', ['email' => $p['user']->email, 'password' => TEST_PANEL_PASSWORD])->assertOk();
    panelPost($this, '/api/v1/admin/auth/mfa/verify', ['code' => currentOtp($p['secret'])])->assertOk();
    panelGet($this, '/api/v1/admin/me')->assertOk()->assertJsonPath('tenant.id', $p['tenant']);
});
