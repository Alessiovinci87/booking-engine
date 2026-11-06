<?php

namespace App\Domain\Reservations\Enums;

/** Reservation.channel (02). */
enum ReservationChannel: string
{
    case DIRECT_WEB = 'DIRECT_WEB';
    case QUOTE = 'QUOTE';
    case MANUAL = 'MANUAL';
}
