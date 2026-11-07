<?php

/*
 * Pagamenti (05). Le durate qui sono quelle fissate dalla specifica, non impostazioni del gestore.
 */
return [

    // Implementazione di App\Domain\Payments\Gateway\PaymentGateway: "stripe" oppure "fake".
    // "fake" funziona solo con APP_ENV local o testing (checkout simulato per test e demo).
    'gateway' => env('PAYMENT_GATEWAY', 'stripe'),

    // Durata della sessione di checkout (05, passo 2: 30 minuti, minimo Stripe).
    'checkout_session_minutes' => 30,

    // Margine aggiunto alla scadenza della sessione (ADR-0012): Stripe rifiuta expires_at a meno di 30
    // minuti dalla creazione, che segue di qualche istante il passo 1 di 05.
    'checkout_session_margin_seconds' => 120,

    // Scadenza provvisoria dell'occupazione prima della sessione (05, passo 1).
    'provisional_hold_minutes' => 45,

    // Margine tra la scadenza della sessione e quella dell'occupazione (05, passo 3).
    'occupancy_grace_minutes' => 5,

    // La finestra di approvazione delle richieste è per struttura: properties.approval_window_hours
    // (03 "Tempi": 12 h predefinite, 1–48 h, sempre ≤ capture_before − 6 h; ADR-0012 punto 22).

    // Job di rilascio delle occupazioni (05, ogni minuto): il dispatcher legge gli ID dei tenant con
    // app_admin_ops; ogni tenant elabora al massimo questo numero di prenotazioni per motivo e giro.
    'release_dispatcher_connection' => 'pgsql_admin_ops',
    'release_batch_size' => 100,

];
