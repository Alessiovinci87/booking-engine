<?php

namespace App\Domain\Calendar\Enums;

/** ExternalCalendarEvent.echo_kind (02, 06). */
enum EchoKind: string
{
    case NONE = 'NONE';
    case CONFIRMED_ECHO = 'CONFIRMED_ECHO';
    case SUSPECTED_ECHO = 'SUSPECTED_ECHO';
}
