<?php

namespace App\Domain\Inventory\Models;

use App\Domain\Tenancy\Enums\Locale;
use App\Domain\Tenancy\Models\TenantModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Traduzione della struttura (02): it, en; nessuna traduzione automatica.
 *
 * @property string $id
 * @property string $property_id
 * @property Locale $locale
 * @property string $name
 * @property string|null $description
 * @property string|null $house_rules
 * @property-read Property $property relazione su FK NOT NULL (7.4)
 */
class PropertyTranslation extends TenantModel
{
    protected function casts(): array
    {
        return ['locale' => Locale::class];
    }

    /** @return BelongsTo<Property, $this> */
    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }
}
