<?php

namespace App\Domain\Payments\Enums;

/** Payment.method_offline (05, flusso D). */
enum OfflineMethod: string
{
    case BANK_TRANSFER = 'BANK_TRANSFER';
    case CASH = 'CASH';
    case POS = 'POS';
    case OTHER = 'OTHER';
}
