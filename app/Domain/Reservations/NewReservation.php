<?php

namespace App\Domain\Reservations;

use App\Domain\Inventory\Enums\BookingMode;
use App\Domain\Inventory\Enums\PaymentMode;
use App\Domain\Inventory\Models\Unit;
use App\Domain\Pricing\Calculator\PriceQuote;
use App\Domain\Reservations\Enums\ReservationChannel;

/**
 * Dati di una nuova prenotazione (T1 o T2). Il prezzo arriva sempre dal server (PriceCalculator),
 * mai dal client: è lo snapshot che resta nella prenotazione (I14).
 */
final readonly class NewReservation
{
    public function __construct(
        public Unit $unit,
        public StayRange $stay,
        public int $adults,
        public int $children,
        public GuestDetails $guest,
        public ReservationChannel $channel,
        public PriceQuote $price,
        public ?string $quoteId = null,
        // Da un preventivo: modalità congelate nel preventivo (03, sezione 4), altrimenti quelle dell'unità.
        public ?BookingMode $bookingMode = null,
        public ?PaymentMode $paymentMode = null,
    ) {}
}
