<?php

return [
    'validation' => [
        'required_when_published' => 'Required while the property is published.',
        'max_below_base' => 'The maximum occupancy cannot be lower than the base occupancy.',
    ],
    'photos' => [
        'limit' => 'You can upload at most :max photos per unit.',
        'order_mismatch' => 'The order must contain all the photos of the unit.',
    ],
    'availability' => [
        'range_too_long' => 'The range can be at most :months months.',
    ],
];
