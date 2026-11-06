<?php

namespace App\Domain\Calendar\Models;

use App\Domain\Calendar\Enums\SyncRunOutcome;
use App\Domain\Tenancy\Models\TenantModel;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Esecuzione della sincronizzazione di un calendario importato (06, "Elaborazione").
 *
 * @property string $id
 * @property string $external_calendar_id
 * @property CarbonImmutable $started_at
 * @property CarbonImmutable|null $finished_at
 * @property SyncRunOutcome|null $outcome
 * @property int|null $http_status
 * @property string|null $error_code
 * @property int $events_seen
 * @property int $events_added
 * @property int $events_updated
 * @property int $events_pending_removal
 * @property int $events_removed
 * @property int|null $duration_ms
 */
class SyncRun extends TenantModel
{
    protected function casts(): array
    {
        return [
            'started_at' => 'immutable_datetime',
            'finished_at' => 'immutable_datetime',
            'outcome' => SyncRunOutcome::class,
            'http_status' => 'integer',
            'events_seen' => 'integer',
            'events_added' => 'integer',
            'events_updated' => 'integer',
            'events_pending_removal' => 'integer',
            'events_removed' => 'integer',
            'duration_ms' => 'integer',
        ];
    }

    /** @return BelongsTo<ExternalCalendar, $this> */
    public function calendar(): BelongsTo
    {
        return $this->belongsTo(ExternalCalendar::class, 'external_calendar_id');
    }
}
