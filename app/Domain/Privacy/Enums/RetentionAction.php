<?php

namespace App\Domain\Privacy\Enums;

/** RetentionPolicy.action (02, 09). */
enum RetentionAction: string
{
    case ANONYMIZE = 'ANONYMIZE';
    case DELETE = 'DELETE';
}
