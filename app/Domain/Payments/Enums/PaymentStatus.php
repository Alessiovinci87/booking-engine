<?php

namespace App\Domain\Payments\Enums;

/** Payment.status: macchina a stati del pagamento (03, sezione 2). */
enum PaymentStatus: string
{
    case PENDING = 'PENDING';
    case AUTHORIZED = 'AUTHORIZED';
    case CAPTURE_PENDING = 'CAPTURE_PENDING';
    case SUCCEEDED = 'SUCCEEDED';
    case CAPTURE_FAILED = 'CAPTURE_FAILED';
    case AUTHORIZATION_CANCELLED = 'AUTHORIZATION_CANCELLED';
    case FAILED = 'FAILED';
    case EXPIRED = 'EXPIRED';
    case PARTIALLY_REFUNDED = 'PARTIALLY_REFUNDED';
    case REFUNDED = 'REFUNDED';
}
