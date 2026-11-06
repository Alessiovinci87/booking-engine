<?php

namespace App\Domain\Reservations\Models;

use App\Domain\Privacy\PersonalData;
use App\Domain\Tenancy\Enums\Locale;
use App\Domain\Tenancy\Models\TenantModel;
use Carbon\CarbonImmutable;

/**
 * Ospite, un record per prenotazione (02). Campi personali secondo 09 ("Dati raccolti" e
 * "Anonimizzazione effettiva"); il paese resta dopo l'anonimizzazione.
 *
 * @property string $id
 * @property string $first_name
 * @property string $last_name
 * @property string $email
 * @property string $phone
 * @property string $country_code
 * @property Locale $locale
 * @property string|null $notes
 * @property CarbonImmutable|null $anonymized_at
 */
#[PersonalData('first_name', 'last_name', 'email', 'phone', 'notes')]
class Guest extends TenantModel
{
    protected function casts(): array
    {
        return [
            'locale' => Locale::class,
            'anonymized_at' => 'immutable_datetime',
        ];
    }
}
