<?php

/*
 * Messaggi di validazione in italiano per le regole usate finora (pannello e comandi interni).
 * Le regole nuove aggiungono qui la loro chiave.
 */
return [
    'required' => 'Il campo :attribute è obbligatorio.',
    'required_without' => 'Il campo :attribute è obbligatorio se manca :values.',
    'string' => 'Il campo :attribute deve essere un testo.',
    'email' => 'Il campo :attribute deve essere un indirizzo email valido.',
    'max' => [
        'string' => 'Il campo :attribute non può superare :max caratteri.',
    ],
    'min' => [
        'string' => 'Il campo :attribute deve avere almeno :min caratteri.',
    ],
    'password' => [
        'uncompromised' => 'Questa password è comparsa in una violazione di dati nota: scegline un\'altra.',
    ],

    'attributes' => [
        'email' => 'email',
        'password' => 'password',
        'name' => 'nome',
        'code' => 'codice',
        'recovery_code' => 'codice di recupero',
    ],
];
