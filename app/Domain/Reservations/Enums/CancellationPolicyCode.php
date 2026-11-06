<?php

namespace App\Domain\Reservations\Enums;

/** CancellationPolicy.code (02, 05). */
enum CancellationPolicyCode: string
{
    case FLEXIBLE_7D = 'FLEXIBLE_7D';
    case NON_REFUNDABLE = 'NON_REFUNDABLE';
}
