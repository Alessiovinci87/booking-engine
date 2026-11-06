<?php

namespace App\Domain\Payments\Enums;

/** Payment.capture_method (02, 05). */
enum CaptureMethod: string
{
    case AUTOMATIC = 'AUTOMATIC';
    case MANUAL = 'MANUAL';
}
