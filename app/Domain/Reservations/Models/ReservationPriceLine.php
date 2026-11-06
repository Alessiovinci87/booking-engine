<?php

namespace App\Domain\Reservations\Models;

use App\Domain\Pricing\Enums\PriceLineKind;
use App\Domain\Tenancy\Models\TenantModel;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Riga di prezzo congelata alla creazione della prenotazione (04 "Snapshot", I14).
 *
 * @property string $id
 * @property string $reservation_id
 * @property PriceLineKind $kind
 * @property CarbonImmutable|null $night_date
 * @property int $quantity
 * @property int $unit_amount_cents
 * @property int $amount_cents
 * @property int $position
 */
class ReservationPriceLine extends TenantModel
{
    protected function casts(): array
    {
        return [
            'kind' => PriceLineKind::class,
            'night_date' => 'immutable_date',
            'quantity' => 'integer',
            'unit_amount_cents' => 'integer',
            'amount_cents' => 'integer',
            'position' => 'integer',
        ];
    }

    /** @return BelongsTo<Reservation, $this> */
    public function reservation(): BelongsTo
    {
        return $this->belongsTo(Reservation::class);
    }
}
