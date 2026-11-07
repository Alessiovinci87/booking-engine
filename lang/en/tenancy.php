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
    'embed' => [
        'unknown_tenant' => 'Unknown tenant.',
        'invalid' => 'Invalid origin: ":origin". Use https://domain[:port], with no path, query or credentials.',
        'nothing_to_do' => 'Pass --add, --remove or --list.',
        'too_many' => 'At most :max domains per tenant.',
        'empty' => 'No domain registered: the embed view cannot be framed.',
    ],
    'mfa_reset' => [
        'reminder' => 'Verify the manager\'s identity by phone before running this command.',
        'invalid_reason' => 'Reason required: 10-200 characters, no email addresses or phone numbers.',
        'unknown_user' => 'No user with this email.',
        'confirm' => 'Have you verified the identity by phone and want to reset MFA and recovery codes?',
        'aborted' => 'Nothing changed.',
        'done' => 'MFA reset for user :id: sessions closed, MFA must be set up again at next sign-in.',
    ],
];
