<?php

namespace App\Domain\Payments\Gateway;

/** Stato del rimborso restituito dal fornitore. */
enum RefundResultStatus: string
{
    case PENDING = 'pending';
    case SUCCEEDED = 'succeeded';
    case FAILED = 'failed';
}
