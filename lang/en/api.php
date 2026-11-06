<?php

// API messages (07, "Formato errori"). Clients always rely on the code.
return [
    'errors' => [
        'VALIDATION_FAILED' => 'Some data is not valid.',
        'UNAUTHENTICATED' => 'Sign-in required.',
        'MFA_REQUIRED' => 'Two-step verification required.',
        'MFA_SETUP_REQUIRED' => 'Set up two-step verification to continue.',
        'MFA_ALREADY_ENABLED' => 'Two-step verification is already enabled.',
        'INVALID_CREDENTIALS' => 'Incorrect email or password.',
        'INVALID_MFA_CODE' => 'Invalid code.',
        'REAUTH_REQUIRED' => 'Confirm your password to continue.',
        'FORBIDDEN' => 'Operation not allowed.',
        'NOT_FOUND' => 'Resource not found.',
        'RATE_LIMITED' => 'Too many attempts. Please try again later.',
        'SERVER_ERROR' => 'Something went wrong. Please try again later.',
    ],
];
