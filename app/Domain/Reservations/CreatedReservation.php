<?php

namespace App\Domain\Reservations;

use App\Domain\Payments\Models\Payment;
use App\Domain\Reservations\Models\Reservation;

/**
 * Prenotazione appena creata con il token di accesso dell'ospite in chiaro: si mostra una sola volta
 * (nel database resta solo l'hash, 07 "GET /public/reservations/{code}").
 */
final readonly class CreatedReservation
{
    public function __construct(
        public Reservation $reservation,
        public string $accessToken,
        public ?Payment $payment = null,
    ) {}
}
