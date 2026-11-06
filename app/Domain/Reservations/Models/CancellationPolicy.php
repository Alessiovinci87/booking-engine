<?php

namespace App\Domain\Reservations\Models;

use App\Domain\Inventory\Models\Property;
use App\Domain\Reservations\Enums\CancellationPolicyCode;
use App\Domain\Tenancy\Enums\Locale;
use App\Domain\Tenancy\Models\TenantModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Politica di cancellazione di una struttura (02, 05, ADR-0009). Il testo IT/EN è standard per codice
 * (lang/<locale>/cancellation.php) e il gestore non lo modifica nella 1.0. Alla prenotazione se ne
 * copia uno snapshot.
 *
 * @property string $id
 * @property string $property_id
 * @property CancellationPolicyCode $code
 */
class CancellationPolicy extends TenantModel
{
    protected function casts(): array
    {
        return ['code' => CancellationPolicyCode::class];
    }

    /** @return BelongsTo<Property, $this> */
    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    public function title(Locale $locale): string
    {
        return self::translate($this->code, 'title', $locale);
    }

    public function text(Locale $locale): string
    {
        return self::translate($this->code, 'text', $locale);
    }

    private static function translate(CancellationPolicyCode $code, string $part, Locale $locale): string
    {
        $value = __("cancellation.policies.{$code->value}.{$part}", [], $locale->value);

        return is_string($value) ? $value : '';
    }
}
