<?php

namespace App\Domain\Quotes\Models;

use App\Domain\Inventory\Enums\BookingMode;
use App\Domain\Inventory\Enums\PaymentMode;
use App\Domain\Inventory\Models\Unit;
use App\Domain\Privacy\PersonalData;
use App\Domain\Quotes\Enums\QuoteStatus;
use App\Domain\Reservations\Casts\StayRangeCast;
use App\Domain\Reservations\Models\Reservation;
use App\Domain\Reservations\StayRange;
use App\Domain\Tenancy\Enums\Locale;
use App\Domain\Tenancy\Models\Concerns\GuardsStatusWrites;
use App\Domain\Tenancy\Models\Concerns\StatusGuardedBuilder;
use App\Domain\Tenancy\Models\TenantModel;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\UseEloquentBuilder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Preventivo (02) con prezzo congelato (04, I14). Macchina a stati in 03, sezione 4: status si
 * scrive solo dalle StateMachines. guest_name e guest_email sono dati personali (09).
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $unit_id
 * @property StayRange $stay
 * @property int $adults
 * @property int $children
 * @property string|null $guest_name
 * @property string|null $guest_email
 * @property Locale $guest_locale
 * @property int $computed_total_cents
 * @property int $discount_cents
 * @property int $final_total_cents
 * @property list<array<string, mixed>> $price_lines
 * @property BookingMode $booking_mode_snapshot
 * @property PaymentMode $payment_mode_snapshot
 * @property CarbonImmutable $expires_at
 * @property string $public_token_hash
 * @property QuoteStatus $status
 * @property string|null $active_reservation_id
 * @property int $checkout_attempts
 * @property int $max_checkout_attempts
 * @property int $version
 */
#[PersonalData('guest_name', 'guest_email')]
#[UseEloquentBuilder(StatusGuardedBuilder::class)]
class Quote extends TenantModel
{
    use GuardsStatusWrites;

    /** @var list<string> */
    protected $hidden = ['public_token_hash'];

    protected function casts(): array
    {
        return [
            'stay' => StayRangeCast::class,
            'adults' => 'integer',
            'children' => 'integer',
            'guest_locale' => Locale::class,
            'computed_total_cents' => 'integer',
            'discount_cents' => 'integer',
            'final_total_cents' => 'integer',
            'price_lines' => 'array',
            'booking_mode_snapshot' => BookingMode::class,
            'payment_mode_snapshot' => PaymentMode::class,
            'expires_at' => 'immutable_datetime',
            'status' => QuoteStatus::class,
            'checkout_attempts' => 'integer',
            'max_checkout_attempts' => 'integer',
            'version' => 'integer',
        ];
    }

    /** @return BelongsTo<Unit, $this> */
    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    /** @return BelongsTo<Reservation, $this> */
    public function activeReservation(): BelongsTo
    {
        return $this->belongsTo(Reservation::class, 'active_reservation_id');
    }
}
