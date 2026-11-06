<?php

namespace App\Domain\Inventory\Models;

use App\Domain\Calendar\Models\ExternalCalendarEvent;
use App\Domain\Inventory\Enums\ConflictResolution;
use App\Domain\Inventory\Enums\ConflictStatus;
use App\Domain\Privacy\PersonalData;
use App\Domain\Reservations\Casts\StayRangeCast;
use App\Domain\Reservations\StayRange;
use App\Domain\Tenancy\Models\Concerns\GuardsStatusWrites;
use App\Domain\Tenancy\Models\Concerns\StatusGuardedBuilder;
use App\Domain\Tenancy\Models\TenantModel;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\UseEloquentBuilder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Conflitto di inventario (02, 06): segnalazione che rende le date non vendibili. Macchina a stati
 * del conflitto in 03, sezione 5: status si scrive solo dalle StateMachines.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $unit_id
 * @property StayRange $stay
 * @property string|null $occupancy_id
 * @property string|null $external_event_id
 * @property string|null $other_external_event_id
 * @property bool $suspected_echo
 * @property ConflictStatus $status
 * @property CarbonImmutable $detected_at
 * @property CarbonImmutable|null $resolved_at
 * @property string|null $resolved_by
 * @property ConflictResolution|null $resolution
 * @property string|null $resolution_notes
 */
#[UseEloquentBuilder(StatusGuardedBuilder::class)]
#[PersonalData('resolution_notes')]
class InventoryConflict extends TenantModel
{
    use GuardsStatusWrites;

    protected function casts(): array
    {
        return [
            'stay' => StayRangeCast::class,
            'suspected_echo' => 'boolean',
            'status' => ConflictStatus::class,
            'detected_at' => 'immutable_datetime',
            'resolved_at' => 'immutable_datetime',
            'resolution' => ConflictResolution::class,
        ];
    }

    /** @return BelongsTo<Unit, $this> */
    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    /** @return BelongsTo<ExternalCalendarEvent, $this> */
    public function externalEvent(): BelongsTo
    {
        return $this->belongsTo(ExternalCalendarEvent::class, 'external_event_id');
    }
}
