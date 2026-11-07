<?php

/*
 * Allerte operative interne (08 "Monitoraggio e allerte", 6.5, ADR-0014).
 */
return [

    // Indirizzi della piattaforma che ricevono le allerte, separati da virgole. Vuoto = solo log.
    'emails' => env('ALERT_EMAILS', ''),

    // Una email per segnale e chiave al massimo ogni N minuti (il log registra ogni occorrenza).
    'throttle_minutes' => (int) env('ALERT_THROTTLE_MINUTES', 60),

    // Coda in ritardo (08): messaggi dell'outbox pronti da più di N minuti.
    'queue_delay_minutes' => 5,

    // Connessione dei controlli cross-tenant (08: dispatcher con app_admin_ops, solo ID e conteggi).
    'connection' => 'pgsql_admin_ops',

];
