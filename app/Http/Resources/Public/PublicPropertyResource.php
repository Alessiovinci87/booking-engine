<?php

namespace App\Http\Resources\Public;

use App\Domain\Inventory\Models\Property;
use App\Domain\Inventory\Models\Unit;
use App\Domain\Inventory\Translations;
use App\Domain\Pricing\Money;
use App\Domain\Reservations\Models\CancellationPolicy;
use App\Domain\Tenancy\Enums\Locale;
use App\Http\Resources\Admin\PropertyResource;
use App\Http\Resources\Admin\UnitPhotoResource;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection as SupportCollection;

/**
 * Struttura pubblicata per la pagina ospite (07, GET /public/{t}/{p}): testi nella lingua richiesta
 * con translation_fallback se mancano, orari, politiche, unità attive con capienza e foto.
 *
 * Contiene solo dati pubblici della struttura: niente stato interno, versione, commissioni OTA,
 * salute della sincronizzazione o identificativi del tenant.
 */
final class PublicPropertyResource
{
    /**
     * @param  Collection<int, Unit>  $units
     * @param  SupportCollection<string, CancellationPolicy>  $policies  politiche della struttura, per id
     * @return array<string, mixed>
     */
    public static function make(Property $property, Collection $units, SupportCollection $policies, Locale $locale): array
    {
        $policy = fn (?string $id): ?array => self::policy($id === null ? null : $policies->get($id), $locale);

        return [
            'slug' => $property->slug,
            'locale' => $locale->value,
            ...self::texts($property->translations, $property->name, $locale),
            'address' => [
                'line1' => $property->address_line1,
                'line2' => $property->address_line2,
                'postal_code' => $property->postal_code,
                'city' => $property->city,
                'province' => $property->province,
                'country_code' => $property->country_code,
            ],
            'cin_code' => $property->cin_code,
            'timezone' => $property->timezone,
            'contact_email' => $property->contact_email,
            'contact_phone' => $property->contact_phone,
            'checkin_time' => PropertyResource::time($property->checkin_time),
            'checkout_time' => PropertyResource::time($property->checkout_time),
            'privacy_policy_url' => $property->privacy_policy_url,
            'terms_url' => $property->terms_url,
            'cancellation_policy' => $policy($property->default_cancellation_policy_id),
            'units' => $units->map(fn (Unit $unit): array => [
                'id' => $unit->id,
                ...self::texts($unit->translations, $unit->name, $locale),
                'base_occupancy' => $unit->base_occupancy,
                'max_occupancy' => $unit->max_occupancy,
                'booking_mode' => $unit->booking_mode->value,
                'payment_mode' => $unit->payment_mode->value,
                'min_nights' => $unit->min_nights,
                'cleaning_fee' => Money::json($unit->cleaning_fee_cents),
                'extra_guest_fee' => Money::json($unit->extra_guest_fee_cents),
                'cancellation_policy' => $policy($unit->cancellation_policy_id ?? $property->default_cancellation_policy_id),
                'photos' => UnitPhotoResource::collection($unit->photos)->resolve(),
            ])->values()->all(),
        ];
    }

    /**
     * Nome, descrizione e regole nella lingua richiesta; se manca, nell'altra lingua con
     * translation_fallback: true. Senza alcuna traduzione resta il nome interno.
     *
     * @param  Collection<int, covariant Model>  $translations
     * @return array{name: string, description: mixed, house_rules: mixed, translation_fallback: bool}
     */
    private static function texts(Collection $translations, string $name, Locale $locale): array
    {
        $all = Translations::toArray($translations);
        $fallback = $all[$locale->value] === null;
        $texts = $all[$locale->value] ?? $all[Locale::IT->value] ?? $all[Locale::EN->value] ?? [];

        return [
            'name' => is_string($texts['name'] ?? null) ? $texts['name'] : $name,
            'description' => $texts['description'] ?? null,
            'house_rules' => $texts['house_rules'] ?? null,
            'translation_fallback' => $fallback,
        ];
    }

    /** @return array{code: string, title: string, text: string}|null */
    private static function policy(?CancellationPolicy $policy, Locale $locale): ?array
    {
        return $policy === null ? null : [
            'code' => $policy->code->value,
            'title' => $policy->title($locale),
            'text' => $policy->text($locale),
        ];
    }
}
