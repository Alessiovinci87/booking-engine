<?php

namespace App\Domain\Payments\Enums;

/** Refund.status: macchina a stati del rimborso (03, sezione 3). */
enum RefundStatus: string
{
    case PENDING = 'PENDING';
    case SUCCEEDED = 'SUCCEEDED';
    case FAILED = 'FAILED';
    case CANCELLED = 'CANCELLED';
}
