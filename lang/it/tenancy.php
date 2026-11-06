<?php

return [
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
