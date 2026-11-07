<?php

namespace App\Domain\Inventory\Models;

use App\Domain\Inventory\Enums\PropertyStatus;
use App\Domain\Reservations\Models\CancellationPolicy;
use App\Domain\Tenancy\Models\Concerns\HasVersion;
use App\Domain\Tenancy\Models\TenantModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Struttura (02).
 *
 * @property string $id
 * @property int $version
 * @property string $tenant_id
 * @property string $name
 * @property string $slug
 * @property string|null $address_line1
 * @property string|null $address_line2
 * @property string|null $postal_code
 * @property string|null $city
 * @property string|null $province
 * @property string $country_code
 * @property string|null $cin_code
 * @property string|null $contact_email
 * @property string|null $contact_phone
 * @property string|null $checkin_time HH:MM:SS
 * @property string|null $checkout_time HH:MM:SS
 * @property string|null $privacy_policy_url
 * @property string|null $terms_url
 * @property string $timezone
 * @property int $ota_commission_bps
 * @property int $approval_window_hours finestra di approvazione delle richieste, 1–48 h (03, ADR-0012 punto 22)
 * @property PropertyStatus $status
 * @property string|null $default_cancellation_policy_id obbligatoria per pubblicare (ADR-0009)
 */
class Property extends TenantModel
{
    use HasVersion;

    protected function casts(): array
    {
        return [
            'status' => PropertyStatus::class,
            'ota_commission_bps' => 'integer',
            'approval_window_hours' => 'integer',
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
