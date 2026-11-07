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

    /** Stati che occupano le date: esattamente un'occupazione ACTIVE (03, "Stati e occupazione"; I15). */
    public function occupies(): bool
    {
        return match ($this) {
            self::AWAITING_PAYMENT, self::AWAITING_APPROVAL, self::CAPTURING, self::CAPTURE_FAILED, self::CONFIRMED => true,
            self::REJECTED, self::EXPIRED, self::PAYMENT_FAILED, self::CANCELLED => false,
        };
    }

    public function isTerminal(): bool
    {
        return ! $this->occupies();
    }
}
