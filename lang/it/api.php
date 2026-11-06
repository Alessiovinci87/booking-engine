<?php

// Messaggi dell'API (07, "Formato errori"). Il client usa sempre il codice.
return [
    'errors' => [
        'VALIDATION_FAILED' => 'Alcuni dati non sono validi.',
        'UNAUTHENTICATED' => 'Accesso richiesto.',
        'MFA_REQUIRED' => 'Verifica in due passaggi richiesta.',
        'MFA_SETUP_REQUIRED' => 'Configura la verifica in due passaggi per continuare.',
        'MFA_ALREADY_ENABLED' => 'La verifica in due passaggi è già attiva.',
        'INVALID_CREDENTIALS' => 'Email o password non corrette.',
        'INVALID_MFA_CODE' => 'Codice non valido.',
        'REAUTH_REQUIRED' => 'Conferma la password per continuare.',
        'FORBIDDEN' => 'Operazione non consentita.',
        'NOT_FOUND' => 'Risorsa non trovata.',
        'RATE_LIMITED' => 'Troppi tentativi. Riprova più tardi.',
        'CSRF_TOKEN_EXPIRED' => 'La sessione della pagina è scaduta. Riprova.',
        'SERVER_ERROR' => 'Si è verificato un errore. Riprova più tardi.',
        'VERSION_MISMATCH' => 'I dati sono stati modificati nel frattempo. Ricarica e riprova.',
        'EXTERNAL_CALENDARS_ACTIVE' => 'La prenotazione immediata non è possibile con calendari esterni attivi.',
        'NO_ACTIVE_UNITS' => 'Serve almeno un\'unità attiva.',
        'TRANSLATION_IT_MISSING' => 'Manca il testo in italiano.',
        'CANCELLATION_POLICY_MISSING' => 'Manca la politica di cancellazione predefinita.',
        'PAYMENTS_NOT_ENABLED' => 'I pagamenti online non sono ancora attivi.',
        'MIN_NIGHTS_NOT_MET' => 'Il soggiorno non raggiunge il minimo di notti.',
        'OCCUPANCY_EXCEEDED' => 'Il numero di ospiti supera la capienza.',
        'AMOUNT_BELOW_MINIMUM' => 'L\'importo è inferiore al minimo pagabile online.',
    ],
];
