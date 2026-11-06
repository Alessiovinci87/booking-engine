<?php

namespace App\Domain\Calendar\Enums;

/** ExternalCalendarEvent.status (02, 06, rimozione in due passi). */
enum ExternalEventStatus: string
{
    case ACTIVE = 'ACTIVE';
    case PENDING_REMOVAL = 'PENDING_REMOVAL';
    case REMOVED = 'REMOVED';
}
