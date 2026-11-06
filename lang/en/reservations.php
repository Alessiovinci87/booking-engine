<?php

// Bookings: validation messages.
return [
    'validation' => [
        'refund_too_high' => 'The refund cannot exceed the amount collected online (:max cents).',
        'terms_required' => 'You must accept the terms and the privacy policy.',
        'offline_amount' => 'The amount must be between 1 and the outstanding balance (:max cents).',
    ],
];
