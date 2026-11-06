<?php

namespace App\Domain\Tenancy\Enums;

/** Tenant.plan (02, 01 listino). */
enum TenantPlan: string
{
    case STARTER = 'STARTER';
    case PLUS = 'PLUS';
    case PRO = 'PRO';
}
