<?php

namespace App\Domain\Payments\Enums;

/** Refund.method (02). */
enum RefundMethod: string
{
    case STRIPE = 'STRIPE';
    case OFFLINE = 'OFFLINE';
}
