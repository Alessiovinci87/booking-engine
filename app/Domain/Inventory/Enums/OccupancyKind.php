<?php

namespace App\Domain\Inventory\Enums;

/** Occupazione di inventario, campo kind (02). */
enum OccupancyKind: string
{
    case RESERVATION = 'RESERVATION';
    case MANUAL_BLOCK = 'MANUAL_BLOCK';
}
