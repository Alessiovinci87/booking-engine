<?php

namespace App\Domain\Calendar\Enums;

/** IcsFeed.status (02, 06, I19). */
enum IcsFeedStatus: string
{
    case ACTIVE = 'ACTIVE';
    case FROZEN = 'FROZEN';
    case RETIRED = 'RETIRED';
}
