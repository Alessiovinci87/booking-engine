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
        'CSRF_TOKEN_EXPIRED' => 'The page session has expired. Please try again.',
        'SERVER_ERROR' => 'Something went wrong. Please try again later.',
        'VERSION_MISMATCH' => 'The data was changed in the meantime. Reload and try again.',
        'EXTERNAL_CALENDARS_ACTIVE' => 'Instant booking is not possible with active external calendars.',
        'NO_ACTIVE_UNITS' => 'At least one active unit is required.',
        'TRANSLATION_IT_MISSING' => 'The Italian text is missing.',
        'CANCELLATION_POLICY_MISSING' => 'The default cancellation policy is missing.',
        'PAYMENTS_NOT_ENABLED' => 'Online payments are not enabled yet.',
        'MIN_NIGHTS_NOT_MET' => 'The stay does not reach the minimum number of nights.',
        'OCCUPANCY_EXCEEDED' => 'The number of guests exceeds the capacity.',
        'AMOUNT_BELOW_MINIMUM' => 'The amount is below the minimum that can be paid online.',
    ],
];
