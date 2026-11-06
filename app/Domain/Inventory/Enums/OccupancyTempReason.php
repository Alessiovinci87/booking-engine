<?php

namespace App\Domain\Inventory\Enums;

/** Occupazione di inventario, campo temp_reason (02, 03). */
enum OccupancyTempReason: string
{
    case CHECKOUT = 'CHECKOUT';
    case AWAITING_APPROVAL = 'AWAITING_APPROVAL';
    case CAPTURING = 'CAPTURING';
    case CAPTURE_FAILED = 'CAPTURE_FAILED';
    case PAYMENT_RECOVERY = 'PAYMENT_RECOVERY';
}
