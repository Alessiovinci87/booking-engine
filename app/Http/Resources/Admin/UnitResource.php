<?php

namespace App\Http\Resources\Admin;

use App\Domain\Inventory\Models\Unit;
use App\Domain\Inventory\Translations;
use App\Domain\Pricing\Money;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Unità nel pannello (07). Importi come { amount_cents, currency }.
 *
 * @property Unit $resource
 */
final class UnitResource extends JsonResource
{
    public static $wrap = null;

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $u = $this->resource;

        return [
            'id' => $u->id,
            'property_id' => $u->property_id,
            'name' => $u->name,
            'base_occupancy' => $u->base_occupancy,
            'max_occupancy' => $u->max_occupancy,
            'booking_mode' => $u->booking_mode->value,
            'payment_mode' => $u->payment_mode->value,
            'min_nights' => $u->min_nights,
            'cleaning_fee' => Money::json($u->cleaning_fee_cents),
            'extra_guest_fee' => Money::json($u->extra_guest_fee_cents),
            'cancellation_policy_id' => $u->cancellation_policy_id,
            'sync_health' => $u->sync_health->value,
            'sales_suspended' => $u->sales_suspended,
            'status' => $u->status->value,
            'translations' => Translations::toArray($u->translations),
            'version' => $u->version,
            'created_at' => $u->created_at?->toIso8601ZuluString(),
            'updated_at' => $u->updated_at?->toIso8601ZuluString(),
        ];
    }
}
