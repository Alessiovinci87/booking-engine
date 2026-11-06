<?php

namespace App\Domain\Reservations\Enums;

/** Reservation.confirmation_kind (02, I11). */
enum ConfirmationKind: string
{
    case ONLINE_PAYMENT = 'ONLINE_PAYMENT';
    case ADMINISTRATIVE = 'ADMINISTRATIVE';
}
