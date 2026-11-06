<?php

namespace App\Domain\Tenancy\Enums;

/** TenantMember.role (02, 08): nella 1.0 si crea solo OWNER. */
enum MemberRole: string
{
    case OWNER = 'OWNER';
    case MANAGER = 'MANAGER';
    case STAFF = 'STAFF';
}
