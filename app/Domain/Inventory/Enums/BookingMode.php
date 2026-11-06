<?php

namespace App\Domain\Inventory\Enums;

/** Unit.booking_mode (02, I12). */
enum BookingMode: string
{
    case REQUEST = 'REQUEST';
    case INSTANT = 'INSTANT';
}
