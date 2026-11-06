<?php

namespace App\Domain\Inventory\Enums;

/** InventoryConflict.resolution (02). */
enum ConflictResolution: string
{
    case CANCELLED_DIRECT = 'CANCELLED_DIRECT';
    case CANCELLED_EXTERNAL = 'CANCELLED_EXTERNAL';
    case FALSE_POSITIVE = 'FALSE_POSITIVE';
    case RELOCATED = 'RELOCATED';
    case OTHER = 'OTHER';
}
