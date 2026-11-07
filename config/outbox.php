<?php

use App\Domain\Reservations\Outbox\SendReservationEmail;

/*
 * Consegna dei messaggi in uscita (02 OutboxMessage, 03 "Regole generali").
 */
return [

    // Gestore per tipo di messaggio: classe che implementa App\Domain\Tenancy\Outbox\OutboxHandler.
    // I tipi arrivano con le milestone che li generano (email di conferma, notifiche, ...).
    'handlers' => [
        'reservation.confirmed' => SendReservationEmail::class,
        'reservation.awaiting_approval' => SendReservationEmail::class,
        'reservation.approval_requested' => SendReservationEmail::class,
        'reservation.expired' => SendReservationEmail::class,
        'reservation.payment_failed' => SendReservationEmail::class,
        'reservation.late_payment' => SendReservationEmail::class,
        'reservation.rejected' => SendReservationEmail::class,
        'reservation.approval_expired' => SendReservationEmail::class,
        'reservation.approval_expired_manager' => SendReservationEmail::class,
        'reservation.capture_failed' => SendReservationEmail::class,
        'reservation.recovery_link' => SendReservationEmail::class,
        'reservation.dates_released' => SendReservationEmail::class,
        'reservation.cancelled' => SendReservationEmail::class,
        'reservation.refund_completed' => SendReservationEmail::class,
        'reservation.dispute_opened' => SendReservationEmail::class,
        'reservation.refund_failed' => SendReservationEmail::class,
        'reservation.capture_failed_reminder' => SendReservationEmail::class,
    ],

    // Tentativi massimi prima dello stato FAILED.
    'max_attempts' => 8,

    // Attesa prima del tentativo successivo, in secondi (l'ultimo valore vale per i successivi).
    'backoff_seconds' => [60, 300, 900, 1800, 3600, 7200, 14400],

    // Messaggi pronti selezionati a ogni giro del dispatcher.
    'batch_size' => 500,

    // Connessione del dispatcher: legge gli ID cross-tenant (08), poi ogni messaggio si consegna in un
    // job con app_runtime e contesto tenant.
    'dispatcher_connection' => 'pgsql_admin_ops',

];
