<?php

namespace App\Http\Resources\Admin;

use App\Domain\Inventory\Models\Property;
use App\Domain\Inventory\Translations;
use App\Domain\Reservations\Models\CancellationPolicy;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Struttura nel pannello (07): campi, traduzioni IT/EN, politiche disponibili e versione per If-Match.
 *
 * @property Property $resource
 */
final class PropertyResource extends JsonResource
{
    public static $wrap = null;

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $p = $this->resource;

        return [
            'id' => $p->id,
            'name' => $p->name,
            'slug' => $p->slug,
            'status' => $p->status->value,
            'address_line1' => $p->address_line1,
            'address_line2' => $p->address_line2,
            'postal_code' => $p->postal_code,
            'city' => $p->city,
            'province' => $p->province,
            'country_code' => $p->country_code,
            'cin_code' => $p->cin_code,
            'timezone' => $p->timezone,
            'contact_email' => $p->contact_email,
            'contact_phone' => $p->contact_phone,
            'checkin_time' => self::time($p->checkin_time),
            'checkout_time' => self::time($p->checkout_time),
            'privacy_policy_url' => $p->privacy_policy_url,
            'terms_url' => $p->terms_url,
            'ota_commission_bps' => $p->ota_commission_bps,
            'approval_window_hours' => $p->approval_window_hours,
            'default_cancellation_policy_id' => $p->default_cancellation_policy_id,
            'cancellation_policies' => $p->cancellationPolicies
                ->sortBy(fn (CancellationPolicy $c): string => $c->code->value)
                ->values()
                ->map(fn (CancellationPolicy $c): array => ['id' => $c->id, 'code' => $c->code->value])
                ->all(),
            'translations' => Translations::toArray($p->translations),
            'version' => $p->version,
            'created_at' => $p->created_at?->toIso8601ZuluString(),
            'updated_at' => $p->updated_at?->toIso8601ZuluString(),
        ];
    }

    /** HH:MM dalla colonna time di PostgreSQL. */
    public static function time(?string $value): ?string
    {
        return $value === null ? null : substr($value, 0, 5);
    }
}
