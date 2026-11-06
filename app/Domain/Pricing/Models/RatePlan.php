<?php

namespace App\Domain\Pricing\Models;

use App\Domain\Inventory\Models\Unit;
use App\Domain\Tenancy\Models\Concerns\HasVersion;
use App\Domain\Tenancy\Models\TenantModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Piano tariffario dell'unità (04): prezzo base per notte, uno attivo per unità.
 *
 * @property string $id
 * @property int $version
 * @property string $unit_id
 * @property string $name
 * @property int $base_nightly_cents
 * @property string $currency
 * @property bool $active
 */
class RatePlan extends TenantModel
{
    use HasVersion;

    protected function casts(): array
    {
        return [
            'base_nightly_cents' => 'integer',
            'active' => 'boolean',
        ];
    }

    /** @return BelongsTo<Unit, $this> */
    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    /** @return HasMany<SeasonRule, $this> */
    public function seasons(): HasMany
    {
        return $this->hasMany(SeasonRule::class);
    }
}
