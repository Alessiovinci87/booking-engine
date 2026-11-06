<?php

namespace App\Domain\Pricing\Models;

use App\Domain\Reservations\Casts\StayRangeCast;
use App\Domain\Reservations\StayRange;
use App\Domain\Tenancy\Models\Concerns\HasVersion;
use App\Domain\Tenancy\Models\TenantModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Stagione (04): prezzo per notte nelle notti di date_range [inizio, fine), con priorità e minimo
 * notti facoltativo.
 *
 * @property string $id
 * @property int $version
 * @property string $rate_plan_id
 * @property string $name
 * @property StayRange $date_range
 * @property int $nightly_cents
 * @property int $priority
 * @property int|null $min_nights
 */
class SeasonRule extends TenantModel
{
    use HasVersion;

    protected function casts(): array
    {
        return [
            'date_range' => StayRangeCast::class,
            'nightly_cents' => 'integer',
            'priority' => 'integer',
            'min_nights' => 'integer',
        ];
    }

    /** @return BelongsTo<RatePlan, $this> */
    public function ratePlan(): BelongsTo
    {
        return $this->belongsTo(RatePlan::class);
    }
}
