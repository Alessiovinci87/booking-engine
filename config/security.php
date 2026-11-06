<?php

/*
 * Parametri di sicurezza del pannello (08, "Autenticazione e accesso"; 07, "Rate limit").
 */
return [

    'session' => [
        // Scadenza per inattività e scadenza assoluta della sessione del pannello.
        'idle_minutes' => (int) env('SECURITY_SESSION_IDLE_MINUTES', 120),
        'absolute_minutes' => (int) env('SECURITY_SESSION_ABSOLUTE_MINUTES', 720),
    ],

    'login' => [
        // 5 tentativi ogni 15 minuti, per account e per IP (07).
        'max_attempts' => 5,
        'decay_seconds' => 900,
    ],

    'mfa' => [
        // Nome mostrato nell'app di autenticazione (otpauth issuer).
        'issuer' => env('MFA_ISSUER', env('APP_NAME', 'Booking Engine')),
        'recovery_codes' => 10,
        // Finestra TOTP: un intervallo di 30 s prima e dopo, per gli orologi non sincronizzati.
        'window' => 1,
    ],

    'password' => [
        'min_length' => 12,
    ],

    'admin_rate_limit_per_minute' => 300,

    // API pubblica in lettura (struttura, calcolo prezzo): 120 richieste al minuto per IP (07).
    'public_read_rate_limit_per_minute' => 120,

];
