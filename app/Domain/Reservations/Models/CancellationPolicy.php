<?php

namespace App\Domain\Reservations\Models;

use App\Domain\Inventory\Models\Property;
use App\Domain\Reservations\Enums\CancellationPolicyCode;
use App\Domain\Tenancy\Models\TenantModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Politica di cancellazione della struttura (02, 05), con testi IT/EN. Alla prenotazione se ne copia
 * uno snapshot.
 *
 * @property string $id
 * @property string $property_id
 * @property CancellationPolicyCode $code
 * @property string $text_it
 * @property string $text_en
 */
class CancellationPolicy extends TenantModel
{
    protected function casts(): array
    {
        return ['code' => CancellationPolicyCode::class];
    }

    /** @return BelongsTo<Property, $this> */
    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }
}
