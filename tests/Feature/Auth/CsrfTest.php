<?php

use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;

/*
 * ADR-0008: una richiesta del pannello con token CSRF mancante o scaduto risponde
 * 419 CSRF_TOKEN_EXPIRED nel formato di errore di 07.
 */

/** Laravel salta il controllo CSRF nei test: questa variante lo applica davvero. */
final class EnforcedCsrfToken extends ValidateCsrfToken
{
    protected function runningUnitTests()
    {
        return false;
    }
}

beforeEach(function () {
    config(['sanctum.middleware.validate_csrf_token' => EnforcedCsrfToken::class]);
});

it('rifiuta una richiesta del pannello senza token CSRF con CSRF_TOKEN_EXPIRED', function () {
    $p = panelUser();

    panelPost($this, '/api/v1/admin/auth/login', ['email' => $p['user']->email, 'password' => TEST_PANEL_PASSWORD])
        ->assertStatus(419)
        ->assertJsonPath('error.code', 'CSRF_TOKEN_EXPIRED')
        ->assertJsonPath('error.message', __('api.errors.CSRF_TOKEN_EXPIRED'));

    $this->assertGuest('web');
});

it('accetta la richiesta con il token CSRF della sessione', function () {
    $p = panelUser();

    $this->withSession(['_token' => 'token-di-prova-csrf'])
        ->withHeader('X-CSRF-TOKEN', 'token-di-prova-csrf');

    panelPost($this, '/api/v1/admin/auth/login', ['email' => $p['user']->email, 'password' => TEST_PANEL_PASSWORD])
        ->assertOk();
});
