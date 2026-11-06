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
        'SERVER_ERROR' => 'Si è verificato un errore. Riprova più tardi.',
    ],
];
