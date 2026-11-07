<?php

// "Simulated payment" page (3.3): development and tests only, with PAYMENT_GATEWAY=fake.
return [
    'simulated' => [
        'badge' => 'Test environment',
        'title' => 'Simulated payment',
        'explanation' => 'No real payment: choose the outcome to simulate. Confirmation comes from the payment service, not from this page.',
        'pay' => 'Payment succeeded',
        'decline' => 'Payment declined',
        'abandon' => 'Abandon',
        'closed' => 'This payment session is no longer open.',
        'back' => 'Back to the booking',
    ],
    // stripe:demo-account command (4.1).
    'demo_account' => [
        'gateway_required' => 'Set PAYMENT_GATEWAY=stripe and the Stripe test keys in the .env file.',
        'live_key' => 'The command only works with a Stripe test key (sk_test_…).',
        'tenant_missing' => 'Tenant ":slug" does not exist: run php artisan db:seed --class=DemoSeeder first.',
        'created' => 'Connected Stripe account: :account',
        'link' => 'Complete the onboarding with Stripe test data at this link (it expires in a few minutes; run the command again for a new one):',
    ],
];
