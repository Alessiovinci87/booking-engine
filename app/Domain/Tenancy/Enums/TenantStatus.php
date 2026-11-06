<?php

namespace App\Domain\Tenancy\Enums;

/** Tenant.status (02). */
enum TenantStatus: string
{
    case ACTIVE = 'ACTIVE';
    case SUSPENDED = 'SUSPENDED';
    case OFFBOARDING = 'OFFBOARDING';
    case CLOSED = 'CLOSED';
}
