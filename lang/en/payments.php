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
];
