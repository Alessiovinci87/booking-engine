<?php

return [
    'create' => [
        'password_prompt' => 'Owner password (at least 12 characters)',
        'password_confirm_prompt' => 'Repeat the password',
        'done' => 'Tenant ":slug" created with its owner.',
        'mfa_notice' => 'On first sign-in the owner will have to set up two-factor authentication.',
        'errors' => [
            'invalid_slug' => 'Invalid slug: use lowercase letters, digits and hyphens (3-63 characters, no leading or trailing hyphen).',
            'slug_taken' => 'A tenant with this slug already exists.',
            'email_taken' => 'A user with this email already exists.',
            'password_mismatch' => 'The two passwords do not match.',
        ],
    ],
];
