<?php

namespace App\Domain\Payments\Enums;

/** Payment.purpose (02). */
enum PaymentPurpose: string
{
    case DEPOSIT = 'DEPOSIT';
    case FULL = 'FULL';
    case BALANCE = 'BALANCE';
    case RECOVERY = 'RECOVERY';
    case OFFLINE = 'OFFLINE';
}
