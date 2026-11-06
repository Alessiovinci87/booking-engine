<?php

namespace App\Domain\Tenancy\Enums;

/** AuditLog.actor_type (02, 08). */
enum AuditActorType: string
{
    case USER = 'USER';
    case SYSTEM = 'SYSTEM';
    case SUPPORT = 'SUPPORT';
    case ANONYMOUS = 'ANONYMOUS';
}
