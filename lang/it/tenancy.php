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
    'embed' => [
        'unknown_tenant' => 'Tenant sconosciuto.',
        'invalid' => 'Origin non valido: ":origin". Usa https://dominio[:porta], senza percorso, query o credenziali.',
        'nothing_to_do' => 'Indica --add, --remove oppure --list.',
        'too_many' => 'Al massimo :max domini per tenant.',
        'empty' => 'Nessun dominio registrato: la vista embed non è incorporabile.',
    ],
    'mfa_reset' => [
        'reminder' => 'Verifica l\'identità del gestore per telefono prima di eseguire questo comando.',
        'invalid_reason' => 'Motivazione obbligatoria: 10-200 caratteri, senza email né numeri di telefono.',
        'unknown_user' => 'Nessun utente con questa email.',
        'confirm' => 'Hai verificato l\'identità per telefono e vuoi azzerare MFA e codici di recupero?',
        'aborted' => 'Nessuna modifica.',
        'done' => 'MFA azzerata per l\'utente :id: sessioni chiuse, al prossimo accesso dovrà configurarla di nuovo.',
    ],
];
