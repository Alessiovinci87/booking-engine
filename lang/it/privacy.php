<?php

/*
 * Conservazione dei dati (7.2, 09): messaggi dei comandi di piattaforma.
 */
return [
    'retention' => [
        'invalid_action' => 'Azione non valida: usa status, run o validate.',
        'unknown_tenant' => 'Tenant sconosciuto.',
        'unknown_category' => 'Categoria sconosciuta: usa una lettera da A a H o il nome completo (es. H_PASSWORD_RESET_LINKS).',
        'invalid_by' => 'Indica chi ha validato con --by: nome del professionista, 3-100 caratteri, senza email.',
        'invalid_date' => 'Data della validazione non valida: usa AAAA-MM-GG, non nel futuro.',
        'invalid_days' => 'Durata non valida: un numero intero di giorni tra 1 e 36500.',
        'simulation' => 'non validata: simulazione',
        'validated' => 'validata da :by il :date',
        'validate_warning' => 'Dopo la validazione il job notturno tratterà davvero la categoria :category (anonimizzazione o cancellazione definitiva).',
        'confirm_validate' => 'Hai la validazione scritta del professionista con la base giuridica?',
        'aborted' => 'Nessuna modifica.',
        'validated_done' => 'Categoria :category validata: :days giorni.',
    ],
];
