<?php

namespace App\Domain\Inventory\Models;

use App\Domain\Calendar\Models\ExternalCalendar;
use App\Domain\Calendar\Models\IcsFeed;
use App\Domain\Inventory\Enums\BookingMode;
use App\Domain\Inventory\Enums\PaymentMode;
use App\Domain\Inventory\Enums\SyncHealth;
use App\Domain\Inventory\Enums\UnitStatus;
use App\Domain\Pricing\Models\RatePlan;
use App\Domain\Reservations\Models\CancellationPolicy;
use App\Domain\Reservations\Models\Reservation;
use App\Domain\Tenancy\Models\Concerns\HasVersion;
use App\Domain\Tenancy\Models\TenantModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Unità vendibile (02). Le occupazioni dell'unità si leggono e scrivono solo da InventoryService.
 *
 * @property string $id
 * @property int $version
 * @property string $tenant_id
 * @property string $property_id
 * @property string $name
 * @property int $base_occupancy
 * @property int $max_occupancy
 * @property BookingMode $booking_mode
 * @property PaymentMode $payment_mode
 * @property int $min_nights
 * @property int $cleaning_fee_cents
 * @property int $extra_guest_fee_cents
 * @property SyncHealth $sync_health
 * @property array<string, array{confirmed_at: string, confirmed_by: string|null}> $calendar_checklist
 * @property bool $sales_suspended
 * @property UnitStatus $status
 * @property string|null $cancellation_policy_id facoltativa: senza, vale quella della struttura (ADR-0009)
 * @property-read Property $property relazione su FK NOT NULL (7.4)
 */
class Unit extends TenantModel
{
    use HasVersion;

    protected function casts(): array
    {
        return [
            'base_occupancy' => 'integer',
            'max_occupancy' => 'integer',
            'booking_mode' => BookingMode::class,
            'payment_mode' => PaymentMode::class,
            'min_nights' => 'integer',
            'cleaning_fee_cents' => 'integer',
            'extra_guest_fee_cents' => 'integer',
            'sync_health' => SyncHealth::class,
            'calendar_checklist' => 'array',
            'sales_suspended' => 'boolean',
            'status' => UnitStatus::class,
        ];
    }

    /** @return BelongsTo<Property, $this> */
    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    /** @return BelongsTo<CancellationPolicy, $this> */
    public function cancellationPolicy(): BelongsTo
    {
        return $this->belongsTo(CancellationPolicy::class);
    }

    /** @return HasMany<UnitTranslation, $this> */
    public function translations(): HasMany
    {
        return $this->hasMany(UnitTranslation::class);
    }

    /** @return HasMany<UnitPhoto, $this> */
    public function photos(): HasMany
    {
        return $this->hasMany(UnitPhoto::class);
    }

    /** @return HasMany<RatePlan, $this> */
    public function ratePlans(): HasMany
    {
        return $this->hasMany(RatePlan::class);
    }

    /** @return HasMany<Reservation, $this> */
    public function reservations(): HasMany
    {
        return $this->hasMany(Reservation::class);
    }

    /** @return HasMany<ExternalCalendar, $this> */
    public function externalCalendars(): HasMany
    {
        return $this->hasMany(ExternalCalendar::class);
    }

    /** @return HasMany<IcsFeed, $this> */
    public function icsFeeds(): HasMany
    {
        return $this->hasMany(IcsFeed::class);
    }
}
