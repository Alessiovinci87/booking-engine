<?php

namespace App\Domain\Inventory\Enums;

/** Unit.sync_health (02, 06). */
enum SyncHealth: string
{
    case HEALTHY = 'HEALTHY';
    case WARNING = 'WARNING';
    case UNHEALTHY = 'UNHEALTHY';
    case NONE = 'NONE';
}
