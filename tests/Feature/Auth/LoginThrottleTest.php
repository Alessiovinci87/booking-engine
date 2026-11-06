<?php

/*
 * Limite ai tentativi (07): 5 ogni 15 minuti per account e per IP; stesso limite per i codici MFA.
 */

it('blocca l\'account dopo 5 tentativi falliti anche con la password giusta', function () {
    $p = panelUser();

    foreach (range(1, 5) as $i) {
        panelPost($this, '/api/v1/admin/auth/login', ['email' => $p['user']->email, 'password' => 'sbagliata-'.$i])
            ->assertUnauthorized();
    }

    panelPost($this, '/api/v1/admin/auth/login', ['email' => $p['user']->email, 'password' => TEST_PANEL_PASSWORD])
        ->assertStatus(429)
        ->assertJsonPath('error.code', 'RATE_LIMITED')
        ->assertHeader('Retry-After');

    $this->assertGuest('web');
});

it('blocca l\'account anche da un altro IP', function () {
    $p = panelUser();

    foreach (range(1, 5) as $i) {
        $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.'.$i]);
        panelPost($this, '/api/v1/admin/auth/login', ['email' => $p['user']->email, 'password' => 'sbagliata']);
    }

    $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.99']);
    panelPost($this, '/api/v1/admin/auth/login', ['email' => $p['user']->email, 'password' => TEST_PANEL_PASSWORD])
        ->assertStatus(429);
});

it('blocca l\'IP dopo 5 tentativi su account diversi', function () {
    $p = panelUser();
    $this->withServerVariables(['REMOTE_ADDR' => '10.0.1.1']);

    foreach (range(1, 5) as $i) {
        panelPost($this, '/api/v1/admin/auth/login', ['email' => 'inesistente-'.$i.'@example.test', 'password' => 'sbagliata']);
    }

    panelPost($this, '/api/v1/admin/auth/login', ['email' => $p['user']->email, 'password' => TEST_PANEL_PASSWORD])
        ->assertStatus(429);
});

it('azzera il contatore dell\'account dopo un accesso riuscito', function () {
    $p = panelUser();
    $this->withServerVariables(['REMOTE_ADDR' => '10.0.2.1']);

    foreach (range(1, 3) as $i) {
        panelPost($this, '/api/v1/admin/auth/login', ['email' => $p['user']->email, 'password' => 'sbagliata']);
    }

    panelPost($this, '/api/v1/admin/auth/login', ['email' => $p['user']->email, 'password' => TEST_PANEL_PASSWORD])->assertOk();

    // Nuovo IP, così conta solo l'account: altri 4 errori non bastano a bloccarlo.
    $this->withServerVariables(['REMOTE_ADDR' => '10.0.2.2']);

    foreach (range(1, 4) as $i) {
        panelPost($this, '/api/v1/admin/auth/login', ['email' => $p['user']->email, 'password' => 'sbagliata'])->assertUnauthorized();
    }
});

it('limita i tentativi di codice MFA', function () {
    $p = panelUser();
    actingAsPanelUser($this, $p['user'], mfaVerified: false);

    foreach (range(1, 5) as $i) {
        $this->postJson('/api/v1/admin/auth/mfa/verify', ['code' => '000000'])->assertUnauthorized();
    }

    $this->postJson('/api/v1/admin/auth/mfa/verify', ['code' => currentOtp($p['secret'])])
        ->assertStatus(429)
        ->assertJsonPath('error.code', 'RATE_LIMITED');
});
