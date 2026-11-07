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

    // Password dimenticata (6.11, ADR-0016): link monouso di 60 minuti; richieste limitate per email (in
    // hash) e per IP, nella stessa finestra di un'ora.
    'password_reset' => [
        'ttl_minutes' => 60,
        'per_email' => 3,
        'per_ip' => 10,
        'decay_seconds' => 3600,
    ],

    'admin_rate_limit_per_minute' => 300,

    // Proxy davanti all'applicazione (load balancer, reverse proxy del provider; 6.7): indirizzi o reti
    // separati da virgole, oppure "*" se l'applicazione è raggiungibile solo dal proxy. Senza questa
    // impostazione, dietro un proxy, l'IP del client sarebbe quello del proxy (limiti per IP condivisi da
    // tutti) e le richieste https risulterebbero http (niente HSTS). Vuoto in locale.
    'trusted_proxies' => env('TRUSTED_PROXIES'),

    // HSTS sulle risposte https (08 "Dati e cifratura"): un anno, sottodomini compresi; 0 = disattivato.
    'hsts_max_age' => (int) env('SECURITY_HSTS_MAX_AGE', 31536000),

    // API pubblica in lettura (struttura, calcolo prezzo): 120 richieste al minuto per IP (07).
    'public_read_rate_limit_per_minute' => 120,

    // Creazione di prenotazioni dalla pagina ospite: 10 richieste ogni 10 minuti per IP e per email (07).
    'public_booking_rate_limit' => ['attempts' => 10, 'minutes' => 10],

    // Store della cache per le risposte dell'Idempotency-Key (07, I9): null = store predefinito
    // (Redis in sviluppo e produzione, array nei test).
    'idempotency_store' => env('IDEMPOTENCY_CACHE_STORE'),

    // Origini aggiuntive per img-src della CSP (08), ad esempio il CDN delle foto se diverso
    // dall'origine dell'app: elenco separato da virgole in CSP_IMG_SRC.
    'csp_img_src' => array_values(array_filter(array_map('trim', explode(',', (string) env('CSP_IMG_SRC', ''))))),

];
