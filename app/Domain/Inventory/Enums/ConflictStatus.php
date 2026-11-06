<?php

namespace App\Domain\Inventory\Enums;

/** InventoryConflict.status: macchina a stati del conflitto (03, sezione 5). */
enum ConflictStatus: string
{
    case OPEN = 'OPEN';
    case INVESTIGATING = 'INVESTIGATING';
    case RESOLVED = 'RESOLVED';
    case IGNORED = 'IGNORED';
}
