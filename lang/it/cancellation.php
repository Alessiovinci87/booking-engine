<?php

/*
 * Testi standard delle politiche di cancellazione (05 "Politiche di cancellazione", ADR-0009).
 * Non modificabili dal gestore nella 1.0.
 */
return [
    'policies' => [
        'FLEXIBLE_7D' => [
            'title' => 'Flessibile (7 giorni)',
            'text' => 'Cancellazione gratuita fino a 7 giorni prima dell\'arrivo (entro le 00:00 del giorno di arrivo, ora della struttura): rimborso del 100% di quanto pagato online. Per cancellazioni successive non è previsto alcun rimborso.',
        ],
        'NON_REFUNDABLE' => [
            'title' => 'Non rimborsabile',
            'text' => 'La prenotazione non è rimborsabile: in caso di cancellazione non è previsto alcun rimborso.',
        ],
    ],
];
