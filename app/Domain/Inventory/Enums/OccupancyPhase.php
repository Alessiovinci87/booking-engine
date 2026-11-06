<?php

namespace App\Domain\Inventory\Enums;

/** Occupazione di inventario, campo phase (02). */
enum OccupancyPhase: string
{
    case TEMPORARY = 'TEMPORARY';
    case FIRM = 'FIRM';
}
