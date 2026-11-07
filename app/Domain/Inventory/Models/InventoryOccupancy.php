<?php

namespace App\Domain\Inventory\Models;

use App\Domain\Inventory\Enums\OccupancyKind;
use App\Domain\Inventory\Enums\OccupancyPhase;
use App\Domain\Inventory\Enums\OccupancyStatus;
use App\Domain\Inventory\Enums\OccupancyTempReason;
use App\Domain\Privacy\PersonalData;
use App\Domain\Reservations\Casts\StayRangeCast;
use App\Domain\Reservations\Models\Reservation;
use App\Domain\Reservations\StayRange;
use App\Domain\Tenancy\Models\TenantModel;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Occupazione interna, unica fonte delle occupazioni (02, ADR-0002). Si usa e si scrive solo da
 * InventoryService (CLAUDE.md regola 13; tests/Arch/InventoryWritesTest).
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $unit_id
 * @property StayRange $stay
 * @property OccupancyKind $kind
 * @property OccupancyPhase $phase
 * @property OccupancyTempReason|null $temp_reason
 * @property CarbonImmutable|null $expires_at
 * @property string|null $reservation_id
 * @property OccupancyStatus $status
 * @property CarbonImmutable|null $released_at
 * @property string|null $release_reason
 * @property string $ics_uid
 * @property int $ics_sequence
 * @property string|null $note
 * @property-read Unit $unit relazione su FK NOT NULL (7.4)
 */
#[PersonalData('note')]
class InventoryOccupancy extends TenantModel
{
    protected function casts(): array
    {
        return [
            'stay' => StayRangeCast::class,
            'kind' => OccupancyKind::class,
            'phase' => OccupancyPhase::class,
            'temp_reason' => OccupancyTempReason::class,
            'expires_at' => 'immutable_datetime',
            'status' => OccupancyStatus::class,
            'released_at' => 'immutable_datetime',
            'ics_sequence' => 'integer',
        ];
    }

    /** @return BelongsTo<Unit, $this> */
    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    /** @return BelongsTo<Reservation, $this> */
    public function reservation(): BelongsTo
    {
        return $this->belongsTo(Reservation::class);
    }
}
