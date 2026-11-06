<?php

namespace App\Domain\Inventory\Models;

use App\Domain\Inventory\Enums\PropertyStatus;
use App\Domain\Reservations\Models\CancellationPolicy;
use App\Domain\Tenancy\Models\TenantModel;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Struttura (02).
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $name
 * @property string $slug
 * @property string $timezone
 * @property int $ota_commission_bps
 * @property PropertyStatus $status
 */
class Property extends TenantModel
{
    protected function casts(): array
    {
        return [
            'status' => PropertyStatus::class,
            'ota_commission_bps' => 'integer',
        ];
    }

    /** @return HasMany<PropertyTranslation, $this> */
    public function translations(): HasMany
    {
        return $this->hasMany(PropertyTranslation::class);
    }

    /** @return HasMany<Unit, $this> */
    public function units(): HasMany
    {
        return $this->hasMany(Unit::class);
    }

    /** @return HasMany<CancellationPolicy, $this> */
    public function cancellationPolicies(): HasMany
    {
        return $this->hasMany(CancellationPolicy::class);
    }
}
