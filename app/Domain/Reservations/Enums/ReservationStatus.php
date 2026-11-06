<?php

namespace App\Domain\Reservations\Enums;

/** Reservation.status: macchina a stati della prenotazione (03, sezione 1). */
enum ReservationStatus: string
{
    case AWAITING_PAYMENT = 'AWAITING_PAYMENT';
    case AWAITING_APPROVAL = 'AWAITING_APPROVAL';
    case CAPTURING = 'CAPTURING';
    case CAPTURE_FAILED = 'CAPTURE_FAILED';
    case CONFIRMED = 'CONFIRMED';
    case REJECTED = 'REJECTED';
    case EXPIRED = 'EXPIRED';
    case PAYMENT_FAILED = 'PAYMENT_FAILED';
    case CANCELLED = 'CANCELLED';
}
