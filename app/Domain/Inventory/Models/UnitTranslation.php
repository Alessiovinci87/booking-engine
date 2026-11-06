<?php

namespace App\Domain\Inventory\Models;

use App\Domain\Tenancy\Enums\Locale;
use App\Domain\Tenancy\Models\TenantModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Traduzione dell'unità (02): it, en.
 *
 * @property string $id
 * @property string $unit_id
 * @property Locale $locale
 * @property string $name
 * @property string|null $description
 * @property string|null $house_rules
 */
class UnitTranslation extends TenantModel
{
    protected function casts(): array
    {
        return ['locale' => Locale::class];
    }

    /** @return BelongsTo<Unit, $this> */
    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }
}
