<?php

namespace App\Domain\Inventory;

use App\Domain\Inventory\Models\Property;
use App\Domain\Inventory\Models\Unit;
use App\Domain\Pricing\Models\RatePlan;
use App\Domain\Pricing\Money;
use App\Domain\Tenancy\Audit\AuditLogger;
use Illuminate\Validation\ValidationException;

/**
 * Configurazione dell'unità (01, 02, 07). Ogni unità nasce con il suo piano tariffario attivo
 * (04: un solo RatePlan attivo per unità), con il prezzo base indicato alla creazione.
 */
final class UnitService
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @param  array<string, mixed>  $attributes  campi validati, senza stato né tenant
     * @param  array<string, array<string, string|null>|null>  $translations
     */
    public function create(Property $property, array $attributes, int $baseNightlyCents, array $translations = []): Unit
    {
        $unit = new Unit;
        $unit->fill($attributes);
        $unit->property_id = $property->id;
        $this->assertOccupancy($unit);
        $unit->save();

        $ratePlan = new RatePlan;
        $ratePlan->forceFill([
            'unit_id' => $unit->id,
            'name' => __('pricing.default_rate_plan_name'),
            'base_nightly_cents' => $baseNightlyCents,
            'currency' => Money::CURRENCY,
        ])->save();

        Translations::sync($unit->translations(), $translations);
        $this->audit->recordModelChange('unit.created', $unit, ['base_nightly_cents' => $baseNightlyCents]);

        return $unit->refresh();
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @param  array<string, array<string, string|null>|null>|null  $translations
     */
    public function update(Unit $unit, array $attributes, ?array $translations = null): Unit
    {
        $unit->fill($attributes);
        $this->assertOccupancy($unit);
        $dirty = $unit->isDirty();
        $unit->save();

        if ($translations !== null && Translations::sync($unit->translations(), $translations)) {
            if (! $dirty) {
                $unit->bumpVersion();
            }

            $this->audit->record('unit.translations_updated', 'unit', $unit->id, ['locales' => array_keys($translations)]);
        }

        if ($dirty) {
            $this->audit->recordModelChange('unit.updated', $unit);
        }

        return $unit->refresh();
    }

    private function assertOccupancy(Unit $unit): void
    {
        if ($unit->base_occupancy > $unit->max_occupancy) {
            throw ValidationException::withMessages([
                'max_occupancy' => __('inventory.validation.max_below_base'),
            ]);
        }
    }
}
