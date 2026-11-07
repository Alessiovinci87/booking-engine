<?php

use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful;

/*
 * Ri-autenticazione per le azioni sensibili (08): password confermata negli ultimi 15 minuti.
 * Le rotte sensibili vere arrivano con le milestone successive; qui una rotta di prova.
 */

beforeEach(function () {
    Route::middleware(['api', EnsureFrontendRequestsAreStateful::class, 'auth:sanctum', 'panel.fresh', 'mfa', 'reauth'])
        ->post('/api/v1/admin/_test/sensitive', fn () => response()->json(['ok' => true]));
});

afterEach(fn () => $this->travelBack());

it('richiede la conferma della password per le azioni sensibili', function () {
    $p = panelUser();
    actingAsPanelUser($this, $p['user']);

    $this->postJson('/api/v1/admin/_test/sensitive')
        ->assertForbidden()
        ->assertJsonPath('error.code', 'REAUTH_REQUIRED');

    $this->postJson('/api/v1/admin/auth/confirm-password', ['password' => 'sbagliata-sbagliata'])
        ->assertUnauthorized()
        ->assertJsonPath('error.code', 'INVALID_CREDENTIALS');

    $this->postJson('/api/v1/admin/auth/confirm-password', ['password' => TEST_PANEL_PASSWORD])->assertNoContent();

    $this->postJson('/api/v1/admin/_test/sensitive')->assertOk();
});

it('chiede di nuovo la password dopo 15 minuti', function () {
    $p = panelUser();
    actingAsPanelUser($this, $p['user']);

    $this->postJson('/api/v1/admin/auth/confirm-password', ['password' => TEST_PANEL_PASSWORD])->assertNoContent();

    $this->travel(14)->minutes();
    $this->postJson('/api/v1/admin/_test/sensitive')->assertOk();

    $this->travel(2)->minutes();
    $this->postJson('/api/v1/admin/_test/sensitive')->assertForbidden();
});

it('non permette la conferma della password senza MFA verificata', function () {
    $p = panelUser();
    actingAsPanelUser($this, $p['user'], mfaVerified: false);

    $this->postJson('/api/v1/admin/auth/confirm-password', ['password' => TEST_PANEL_PASSWORD])
        ->assertUnauthorized()
        ->assertJsonPath('error.code', 'MFA_REQUIRED');
});
