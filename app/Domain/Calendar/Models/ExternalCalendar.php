<?php

namespace App\Domain\Calendar\Models;

use App\Domain\Calendar\Enums\ChannelSource;
use App\Domain\Calendar\Enums\ExternalCalendarStatus;
use App\Domain\Inventory\Models\Unit;
use App\Domain\Tenancy\Models\TenantModel;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Calendario importato da un'OTA (02, 06). L'URL è cifrato a riposo (08) e si scarica solo con
 * SafeHttpFetcher.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $unit_id
 * @property ChannelSource $source
 * @property string $url_encrypted
 * @property string|null $etag
 * @property string|null $last_modified
 * @property CarbonImmutable|null $last_attempt_at
 * @property CarbonImmutable|null $last_success_at
 * @property int $consecutive_failures
 * @property ExternalCalendarStatus $status
 * @property CarbonImmutable|null $next_poll_at
 * @property CarbonImmutable|null $url_changed_at
 * @property bool $demo calendario dimostrativo del seeder: niente polling né salute (ADR-0013 punto 44)
 * @property-read Unit $unit relazione su FK NOT NULL (7.4)
 */
class ExternalCalendar extends TenantModel
{
    /** @var list<string> */
    protected $hidden = ['url_encrypted'];

    protected function casts(): array
    {
        return [
            'source' => ChannelSource::class,
            'url_encrypted' => 'encrypted',
            'last_attempt_at' => 'immutable_datetime',
            'last_success_at' => 'immutable_datetime',
            'consecutive_failures' => 'integer',
            'status' => ExternalCalendarStatus::class,
            'next_poll_at' => 'immutable_datetime',
            'url_changed_at' => 'immutable_datetime',
            'demo' => 'boolean',
        ];
    }

    /** @return BelongsTo<Unit, $this> */
    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    /** @return HasMany<ExternalCalendarEvent, $this> */
    public function events(): HasMany
    {
        return $this->hasMany(ExternalCalendarEvent::class);
    }

    /** @return HasMany<SyncRun, $this> */
    public function syncRuns(): HasMany
    {
        return $this->hasMany(SyncRun::class);
    }
}
