<?php

use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Laravel\Sanctum\Http\Middleware\AuthenticateSession;
use Laravel\Sanctum\Sanctum;

/*
 * Sanctum in modalità SPA (08): il pannello su /app usa i cookie di sessione dello stesso dominio,
 * con CSRF. Nessun token API personale nella 1.0.
 */
return [

    'stateful' => explode(',', (string) env('SANCTUM_STATEFUL_DOMAINS', sprintf(
        '%s%s',
        'localhost,localhost:8000,127.0.0.1,127.0.0.1:8000',
        Sanctum::currentApplicationUrlWithPort(),
    ))),

    'guard' => ['web'],

    // Nessun token: la durata dell'accesso è quella della sessione (config/security.php).
    'expiration' => null,

    'token_prefix' => '',

    'middleware' => [
        'authenticate_session' => AuthenticateSession::class,
        'encrypt_cookies' => EncryptCookies::class,
        'validate_csrf_token' => ValidateCsrfToken::class,
    ],

];
