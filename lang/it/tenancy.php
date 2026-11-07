<?php

return [
    'dpa' => [
        'invalid' => 'Tenant sconosciuto o versione del DPA non valida (massimo 50 caratteri).',
        'invalid_date' => 'Data della firma non valida: usa AAAA-MM-GG, non nel futuro.',
        'done' => 'DPA versione :version registrato, firmato il :date.',
    ],
    'create' => [
        'password_prompt' => 'Password del proprietario (almeno 12 caratteri)',
        'password_confirm_prompt' => 'Ripeti la password',
        'done' => 'Tenant ":slug" creato con il suo proprietario.',
        'mfa_notice' => 'Al primo accesso il proprietario dovrà configurare l\'autenticazione a due fattori.',
        'errors' => [
            'invalid_slug' => 'Slug non valido: usa minuscole, cifre e trattini (3-63 caratteri, senza trattino iniziale o finale).',
            'slug_taken' => 'Esiste già un tenant con questo slug.',
            'email_taken' => 'Esiste già un utente con questa email.',
            'password_mismatch' => 'Le due password non coincidono.',
        ],
    ],
];
