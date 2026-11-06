<?php

namespace App\Domain\Inventory\Models;

use App\Domain\Inventory\Enums\PropertyStatus;
use App\Domain\Reservations\Models\CancellationPolicy;
use App\Domain\Tenancy\Models\TenantModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
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
 * @property string|null $default_cancellation_policy_id obbligatoria per pubblicare (ADR-0009)
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

    /** @return BelongsTo<CancellationPolicy, $this> */
    public function defaultCancellationPolicy(): BelongsTo
    {
        return $this->belongsTo(CancellationPolicy::class, 'default_cancellation_policy_id');
    }
}
