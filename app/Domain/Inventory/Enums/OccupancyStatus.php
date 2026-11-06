<?php

namespace App\Domain\Inventory\Enums;

/** Occupazione di inventario, campo status (02): scritto solo da InventoryService. */
enum OccupancyStatus: string
{
    case ACTIVE = 'ACTIVE';
    case RELEASED = 'RELEASED';
}
