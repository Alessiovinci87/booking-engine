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
    'support' => [
        'unknown_tenant' => 'Tenant sconosciuto.',
        'unknown_operator' => 'Nessun utente con questa email.',
        'refused' => 'Operazione rifiutata: :code',
        'opened' => 'Accesso di supporto :id aperto fino a :expires (UTC), in sola lettura.',
        'closed' => 'Accessi di supporto chiusi: :count.',
        'operator_added' => 'Utente :id segnato come operatore di piattaforma.',
        'operator_removed' => 'Utente :id non è più operatore di piattaforma.',
        'mfa_notice' => 'L\'operatore non ha ancora la MFA attiva: support:open rifiuterà finché non la configura.',
    ],
    'offboarding' => [
        'unknown_tenant' => 'Tenant sconosciuto.',
        'invalid_action' => 'Azione non valida: usa status, start, export, retire-check o purge.',
        'refused' => 'Operazione rifiutata: :code :details',
        'status_line' => 'Stato :status · avvio :started · ritiro automatico dal :auto · feed disattivati :disabled',
        'confirm_start' => 'Il cliente ha chiesto di uscire? Pagina ospite e nuove prenotazioni verranno disattivate e i feed congelati.',
        'started' => 'Uscita avviata: pannello in sola lettura, feed congelati.',
        'export_requested' => 'Export :id richiesto: i proprietari riceveranno un\'email quando è pronto.',
        'retire_check' => 'Feed ritirati: :retired; ancora letti (restano congelati): :still_read.',
        'invalid_instruction' => 'Istruzione non valida: 10-200 caratteri, senza email né numeri di telefono.',
        'purge_warning' => 'La cancellazione è definitiva: restano solo la riga del tenant e il registro delle operazioni.',
        'confirm_purge' => 'Confermi la cancellazione dei dati del tenant secondo il DPA?',
        'aborted' => 'Nessuna modifica.',
        'purged' => 'Dati cancellati: :rows righe, :files file. Tenant CLOSED.',
    ],
    'export' => [
        'readme' => [
            'Export completo dei dati di :tenant, generato il :date (UTC).',
            'Un file CSV per tabella (separatore ";", codifica UTF-8) e export.json con gli stessi dati.',
            'prenotazioni_future.csv elenca le :future prenotazioni con partenza futura: vanno gestite con il nuovo sistema.',
            'Importi in centesimi di euro; date di soggiorno nel formato [arrivo, partenza); orari in UTC.',
        ],
    ],
];
