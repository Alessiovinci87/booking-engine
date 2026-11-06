<?php

namespace App\Domain\Reservations\Enums;

/** refund_status derivato dai Refund, non memorizzato (02). */
enum RefundSummaryStatus: string
{
    case NONE = 'NONE';
    case PENDING = 'PENDING';
    case SUCCEEDED = 'SUCCEEDED';
    case PARTIALLY_FAILED = 'PARTIALLY_FAILED';
    case FAILED = 'FAILED';
}
