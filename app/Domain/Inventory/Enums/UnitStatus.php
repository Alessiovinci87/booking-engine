<?php

namespace App\Domain\Inventory\Enums;

/** Unit.status (02). */
enum UnitStatus: string
{
    case ACTIVE = 'ACTIVE';
    case INACTIVE = 'INACTIVE';
}
