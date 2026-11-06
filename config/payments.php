<?php

/*
 * Pagamenti (05). Le durate qui sono quelle fissate dalla specifica, non impostazioni del gestore.
 */
return [

    // Implementazione di App\Domain\Payments\Gateway\PaymentGateway: "stripe" (M4) oppure "fake".
    // "fake" funziona solo con APP_ENV local o testing (checkout simulato per test e demo).
    'gateway' => env('PAYMENT_GATEWAY', 'stripe'),

    // Durata della sessione di checkout (05, passo 2: 30 minuti, minimo Stripe).
    'checkout_session_minutes' => 30,

    // Scadenza provvisoria dell'occupazione prima della sessione (05, passo 1).
    'provisional_hold_minutes' => 45,

    // Margine tra la scadenza della sessione e quella dell'occupazione (05, passo 3).
    'occupancy_grace_minutes' => 5,

];
