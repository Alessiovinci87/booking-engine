<?php

namespace App\Domain\Calendar\Enums;

/** ExternalCalendar.status (02, 06). */
enum ExternalCalendarStatus: string
{
    case ACTIVE = 'ACTIVE';
    case DEGRADED = 'DEGRADED';
    case UNHEALTHY = 'UNHEALTHY';
    case DISABLED = 'DISABLED';
}
