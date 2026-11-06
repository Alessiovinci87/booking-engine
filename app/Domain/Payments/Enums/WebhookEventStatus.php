<?php

namespace App\Domain\Payments\Enums;

/** WebhookEvent.status (05, "Webhook"). */
enum WebhookEventStatus: string
{
    case RECEIVED = 'RECEIVED';
    case PROCESSED = 'PROCESSED';
    case IGNORED = 'IGNORED';
    case FAILED = 'FAILED';
}
