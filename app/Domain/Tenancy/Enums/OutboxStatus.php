<?php

namespace App\Domain\Tenancy\Enums;

/** OutboxMessage.status: modificato solo da OutboxMessageStateMachine. */
enum OutboxStatus: string
{
    case PENDING = 'PENDING';
    case SENT = 'SENT';
    case FAILED = 'FAILED';
}
