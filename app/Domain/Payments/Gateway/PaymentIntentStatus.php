<?php

namespace App\Domain\Payments\Gateway;

/** Stato del PaymentIntent collegato alla sessione (sottoinsieme usato da 05). */
enum PaymentIntentStatus: string
{
    case REQUIRES_PAYMENT_METHOD = 'requires_payment_method';
    case REQUIRES_CONFIRMATION = 'requires_confirmation';
    case REQUIRES_ACTION = 'requires_action';
    case PROCESSING = 'processing';
    case REQUIRES_CAPTURE = 'requires_capture';
    case SUCCEEDED = 'succeeded';
    case CANCELED = 'canceled';
}
