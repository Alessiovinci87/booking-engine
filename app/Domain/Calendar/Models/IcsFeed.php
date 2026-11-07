<?php

namespace App\Domain\Calendar\Models;

use App\Domain\Calendar\Enums\ChannelSource;
use App\Domain\Calendar\Enums\IcsFeedStatus;
use App\Domain\Inventory\Models\Unit;
use App\Domain\Tenancy\Models\TenantModel;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Feed iCal esportato, uno per unità e destinazione (02, 06, ADR-0003). Il token è cifrato a riposo,
 * con hash per la ricerca (08).
 *
 * @property string $id
 * @property string $unit_id
 * @property ChannelSource $destination
 * @property string|null $paired_external_calendar_id
 * @property string $token_encrypted
 * @property string $token_hash
 * @property IcsFeedStatus $status
 * @property CarbonImmutable|null $last_fetched_at
 * @property string|null $last_fetch_user_agent
 * @property CarbonImmutable|null $rotated_at
 * @property CarbonImmutable|null $frozen_at
 * @property CarbonImmutable|null $removal_confirmed_at conferma del gestore: feed tolto dall'OTA (7.1)
 */
class IcsFeed extends TenantModel
{
    /** @var list<string> */
    protected $hidden = ['token_encrypted', 'token_hash'];

    protected function casts(): array
    {
        return [
            'destination' => ChannelSource::class,
            'token_encrypted' => 'encrypted',
            'status' => IcsFeedStatus::class,
            'last_fetched_at' => 'immutable_datetime',
            'rotated_at' => 'immutable_datetime',
            'frozen_at' => 'immutable_datetime',
            'removal_confirmed_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Unit, $this> */
    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    /** @return BelongsTo<ExternalCalendar, $this> */
    public function pairedCalendar(): BelongsTo
    {
        return $this->belongsTo(ExternalCalendar::class, 'paired_external_calendar_id');
    }
}
