<?php

namespace App\Domain\Inventory\Enums;

/** Unit.payment_mode (02, 04). */
enum PaymentMode: string
{
    case DEPOSIT_30 = 'DEPOSIT_30';
    case FULL = 'FULL';
}
