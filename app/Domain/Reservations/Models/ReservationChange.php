<?php

namespace App\Domain\Reservations\Models;

use App\Domain\Privacy\PersonalData;
use App\Domain\Reservations\Casts\StayRangeCast;
use App\Domain\Reservations\StayRange;
use App\Domain\Tenancy\Models\TenantModel;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Modifica di una prenotazione confermata (03, T18): date, ospiti e totale prima e dopo.
 *
 * @property string $id
 * @property string $reservation_id
 * @property string|null $changed_by_user_id
 * @property StayRange $previous_stay
 * @property StayRange $new_stay
 * @property int $previous_adults
 * @property int $new_adults
 * @property int $previous_children
 * @property int $new_children
 * @property int $previous_contract_total_cents
 * @property int $new_contract_total_cents
 * @property string|null $note
 * @property CarbonImmutable $occurred_at
 */
#[PersonalData('note')]
class ReservationChange extends TenantModel
{
    protected function casts(): array
    {
        return [
            'previous_stay' => StayRangeCast::class,
            'new_stay' => StayRangeCast::class,
            'previous_adults' => 'integer',
            'new_adults' => 'integer',
            'previous_children' => 'integer',
            'new_children' => 'integer',
            'previous_contract_total_cents' => 'integer',
            'new_contract_total_cents' => 'integer',
            'occurred_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Reservation, $this> */
    public function reservation(): BelongsTo
    {
        return $this->belongsTo(Reservation::class);
    }
}
