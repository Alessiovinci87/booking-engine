<?php

namespace App\Domain\Reservations\Casts;

use App\Domain\Reservations\StayRange;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * Cast Eloquent delle colonne DATERANGE (stay, previous_stay, new_stay, date_range) da e verso
 * StayRange [check_in, check_out).
 *
 * Accetta in scrittura qualsiasi valore e rifiuta tutto ciò che non è un StayRange.
 *
 * @implements CastsAttributes<StayRange|null, mixed>
 */
final class StayRangeCast implements CastsAttributes
{
    public function get(Model $model, string $key, mixed $value, array $attributes): ?StayRange
    {
        if ($value === null) {
            return null;
        }

        if (! is_string($value)) {
            throw new InvalidArgumentException('Valore DATERANGE non valido.');
        }

        return StayRange::fromPostgres($value);
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null) {
            return null;
        }

        if (! $value instanceof StayRange) {
            throw new InvalidArgumentException('Assegnare un StayRange.');
        }

        return $value->toPostgres();
    }
}
