<?php

namespace App\Http\Resources\Admin;

use App\Domain\Pricing\Models\SeasonRule;
use App\Domain\Pricing\Money;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Stagione (04): notti come prima e ultima comprese (nel database [prima, ultima + 1)).
 *
 * @property SeasonRule $resource
 */
final class SeasonResource extends JsonResource
{
    public static $wrap = null;

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $season = $this->resource;

        return [
            'id' => $season->id,
            'name' => $season->name,
            'first_night' => $season->date_range->checkIn->toDateString(),
            'last_night' => $season->date_range->checkOut->subDay()->toDateString(),
            'nightly' => Money::json($season->nightly_cents),
            'priority' => $season->priority,
            'min_nights' => $season->min_nights,
            'version' => $season->version,
        ];
    }
}
