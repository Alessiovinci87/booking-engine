<?php

namespace App\Domain\Inventory;

use App\Domain\Inventory\Enums\PropertyStatus;
use App\Domain\Inventory\Enums\UnitStatus;
use App\Domain\Inventory\Models\Property;
use App\Domain\Inventory\Models\Unit;
use App\Domain\Tenancy\Enums\TenantStatus;
use App\Domain\Tenancy\Models\Tenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * Ciò che la superficie pubblica può vedere (07, 02 "Disponibilità effettiva"): struttura PUBLISHED
 * di un tenant ACTIVE e sue unità ACTIVE (elenco). Tutto il resto risulta inesistente (404), senza rivelare
 * se la struttura esiste in bozza o se il tenant è sospeso. Va chiamato dentro il contesto tenant.
 */
final class PublicCatalog
{
    public function property(string $slug): Property
    {
        $tenantActive = Tenant::query()->where('status', TenantStatus::ACTIVE->value)->exists();

        if (! $tenantActive) {
            throw (new ModelNotFoundException)->setModel(Property::class);
        }

        return Property::query()
            ->where('slug', $slug)
            ->where('status', PropertyStatus::PUBLISHED->value)
            ->firstOrFail();
    }

    /** @return Collection<int, Unit> */
    public function units(Property $property): Collection
    {
        return $this->activeUnits($property)->orderBy('created_at')->orderBy('id')->get()
            ->each(fn (Unit $unit) => $unit->setRelation('property', $property));
    }

    /**
     * Unità della struttura pubblicata, in qualsiasi stato: availability e quote-price rispondono
     * 409 UNIT_NOT_BOOKABLE per un'unità non attiva o con vendite sospese (ADR-0010, punto 7).
     */
    public function unit(Property $property, string $unitId): Unit
    {
        return Unit::query()->where('property_id', $property->id)->findOrFail($unitId)->setRelation('property', $property);
    }

    /** @return Builder<Unit> */
    private function activeUnits(Property $property): Builder
    {
        return Unit::query()
            ->where('property_id', $property->id)
            ->where('status', UnitStatus::ACTIVE->value);
    }
}
