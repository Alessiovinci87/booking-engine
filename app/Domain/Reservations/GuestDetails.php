<?php

namespace App\Domain\Reservations;

use App\Domain\Tenancy\Enums\Locale;

/** Dati dell'ospite raccolti alla prenotazione (09, "Dati raccolti"). Tutti personali. */
final readonly class GuestDetails
{
    public function __construct(
        public string $firstName,
        public string $lastName,
        public string $email,
        public string $phone,
        public string $countryCode,
        public Locale $locale,
        public ?string $notes = null,
    ) {}
}
