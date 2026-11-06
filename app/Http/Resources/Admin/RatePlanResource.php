<?php

namespace App\Http\Resources\Admin;

use App\Domain\Pricing\Models\RatePlan;
use App\Domain\Pricing\Money;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Piano tariffario attivo dell'unità (04, 07).
 *
 * @property RatePlan $resource
 */
final class RatePlanResource extends JsonResource
{
    public static $wrap = null;

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $plan = $this->resource;

        return [
            'id' => $plan->id,
            'unit_id' => $plan->unit_id,
            'name' => $plan->name,
            'base_nightly' => Money::json($plan->base_nightly_cents, $plan->currency),
            'version' => $plan->version,
        ];
    }
}
