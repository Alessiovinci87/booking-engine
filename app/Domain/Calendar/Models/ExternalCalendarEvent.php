<?php

namespace App\Domain\Calendar\Models;

use App\Domain\Calendar\Enums\EchoKind;
use App\Domain\Calendar\Enums\ExternalEventStatus;
use App\Domain\Privacy\PersonalData;
use App\Domain\Reservations\Casts\StayRangeCast;
use App\Domain\Reservations\StayRange;
use App\Domain\Tenancy\Models\TenantModel;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Evento importato da un calendario esterno (02, 06): un fatto esterno, non un'occupazione interna.
 *
 * @property string $id
 * @property string $external_calendar_id
 * @property string $uid
 * @property StayRange $stay
 * @property string|null $summary_redacted
 * @property string $content_hash
 * @property CarbonImmutable $first_seen_at
 * @property CarbonImmutable $last_seen_at
 * @property CarbonImmutable|null $missing_since
 * @property EchoKind $echo_kind
 * @property string|null $echo_of_ics_uid
 * @property ExternalEventStatus $status
 * @property int $ics_sequence
 */
#[PersonalData('summary_redacted')]
class ExternalCalendarEvent extends TenantModel
{
    protected function casts(): array
    {
        return [
            'stay' => StayRangeCast::class,
            'first_seen_at' => 'immutable_datetime',
            'last_seen_at' => 'immutable_datetime',
            'missing_since' => 'immutable_datetime',
            'echo_kind' => EchoKind::class,
            'status' => ExternalEventStatus::class,
            'ics_sequence' => 'integer',
        ];
    }

    /** @return BelongsTo<ExternalCalendar, $this> */
    public function calendar(): BelongsTo
    {
        return $this->belongsTo(ExternalCalendar::class, 'external_calendar_id');
    }
}
