<?php

namespace App\Domain\Reservations\Enums;

/** Stato economico derivato da Payment e Refund, non memorizzato (02). */
enum EconomicStatus: string
{
    case UNPAID = 'UNPAID';
    case AUTHORIZED = 'AUTHORIZED';
    case DEPOSIT_PAID = 'DEPOSIT_PAID';
    case PAID = 'PAID';
    case PARTIALLY_REFUNDED = 'PARTIALLY_REFUNDED';
    case REFUNDED = 'REFUNDED';
}
