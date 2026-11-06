<?php

namespace App\Domain\Reservations\Enums;

/**
 * Eventi della macchina a stati della prenotazione (03, sezione 1). Ogni evento è un metodo di
 * ReservationStateMachine; la tabella ReservationTransitions dice da quali stati è ammesso.
 */
enum ReservationEvent: string
{
    case START_CHECKOUT = 'start_checkout';
    case CREATE_MANUAL = 'create_manual';
    case PAYMENT_SUCCEEDED = 'payment_succeeded';
    case AUTHORIZATION_SUCCEEDED = 'authorization_succeeded';
    case CHECKOUT_EXPIRED = 'checkout_expired';
    case LATE_PAYMENT = 'late_payment';
    case PAYMENT_FAILED = 'payment_failed';
    case APPROVE = 'approve';
    case REJECT = 'reject';
    case APPROVAL_EXPIRED = 'approval_expired';
    case CAPTURE_SUCCEEDED = 'capture_succeeded';
    case CAPTURE_FAILED = 'capture_failed';
    case CAPTURE_UNKNOWN = 'capture_unknown';
    case SEND_RECOVERY_LINK = 'send_recovery_link';
    case RELEASE_DATES = 'release_dates';
    case CANCEL = 'cancel';
    case MODIFY = 'modify';

    /** Eventi che creano la prenotazione (nessuno stato di partenza). */
    public function creates(): bool
    {
        return $this === self::START_CHECKOUT || $this === self::CREATE_MANUAL;
    }
}
