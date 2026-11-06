<?php

namespace App\Domain\Payments\Gateway;

/** Stato della sessione di checkout come lo riporta il fornitore. */
enum CheckoutSessionStatus: string
{
    case OPEN = 'open';
    case COMPLETE = 'complete';
    case EXPIRED = 'expired';
}
