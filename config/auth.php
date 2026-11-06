<?php

use App\Domain\Tenancy\Models\User;

/*
 * Autenticazione del pannello (08): Sanctum SPA con cookie di sessione sul guard "web", utenti in
 * App\Domain\Tenancy\Models\User. Nessuna registrazione pubblica e nessun reset della password via
 * email nella 1.0 (tenant e proprietario si creano con tenant:create).
 */
return [

    'defaults' => [
        'guard' => env('AUTH_GUARD', 'web'),
        'passwords' => env('AUTH_PASSWORD_BROKER', 'users'),
    ],

    'guards' => [
        'web' => [
            'driver' => 'session',
            'provider' => 'users',
        ],
    ],

    'providers' => [
        'users' => [
            'driver' => 'eloquent',
            'model' => User::class,
        ],
    ],

    'passwords' => [
        'users' => [
            'provider' => 'users',
            'table' => 'password_reset_tokens',
            'expire' => 60,
            'throttle' => 60,
        ],
    ],

    // Ri-autenticazione per le azioni sensibili: conferma della password valida 15 minuti (08).
    'password_timeout' => (int) env('AUTH_PASSWORD_TIMEOUT', 900),

];
