<?php

namespace App\Domain\Reservations\Models;

use App\Domain\Inventory\Enums\BookingMode;
use App\Domain\Inventory\Enums\PaymentMode;
use App\Domain\Inventory\Models\Unit;
use App\Domain\Payments\Models\Payment;
use App\Domain\Privacy\PersonalData;
use App\Domain\Quotes\Models\Quote;
use App\Domain\Reservations\Casts\StayRangeCast;
use App\Domain\Reservations\Enums\ConfirmationKind;
use App\Domain\Reservations\Enums\ReservationChannel;
use App\Domain\Reservations\Enums\ReservationStatus;
use App\Domain\Reservations\StayRange;
use App\Domain\Tenancy\Enums\Locale;
use App\Domain\Tenancy\Models\Concerns\GuardsStatusWrites;
use App\Domain\Tenancy\Models\Concerns\StatusGuardedBuilder;
use App\Domain\Tenancy\Models\TenantModel;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\UseEloquentBuilder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Prenotazione (02). Macchina a stati in 03, sezione 1: status si scrive solo dalle StateMachines.
 * Lo stato economico e quello del rimborso sono derivati da Payment e Refund, non memorizzati.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $unit_id
 * @property string $guest_id
 * @property string|null $quote_id
 * @property string $code
 * @property ReservationChannel $channel
 * @property StayRange $stay
 * @property int $adults
 * @property int $children
 * @property ReservationStatus $status
 * @property ConfirmationKind|null $confirmation_kind
 * @property BookingMode $booking_mode_snapshot
 * @property PaymentMode $payment_mode_snapshot
 * @property array<string, mixed> $cancellation_policy_snapshot
 * @property int $contract_total_cents
 * @property int $deposit_due_cents
 * @property int $balance_due_cents
 * @property string $currency
 * @property Locale $guest_locale
 * @property CarbonImmutable|null $approval_deadline
 * @property CarbonImmutable|null $decision_deadline
 * @property CarbonImmutable|null $recovery_deadline
 * @property CarbonImmutable|null $confirmed_at
 * @property CarbonImmutable|null $cancelled_at
 * @property string|null $cancel_reason
 * @property string|null $public_access_token_hash
 * @property int $version
 */
#[UseEloquentBuilder(StatusGuardedBuilder::class)]
#[PersonalData('cancel_reason')]
class Reservation extends TenantModel
{
    use GuardsStatusWrites;

    /** @var list<string> */
    protected $hidden = ['public_access_token_hash'];

    protected function casts(): array
    {
        return [
            'channel' => ReservationChannel::class,
            'stay' => StayRangeCast::class,
            'adults' => 'integer',
            'children' => 'integer',
            'status' => ReservationStatus::class,
            'confirmation_kind' => ConfirmationKind::class,
            'booking_mode_snapshot' => BookingMode::class,
            'payment_mode_snapshot' => PaymentMode::class,
            'cancellation_policy_snapshot' => 'array',
            'contract_total_cents' => 'integer',
            'deposit_due_cents' => 'integer',
            'balance_due_cents' => 'integer',
            'guest_locale' => Locale::class,
            'approval_deadline' => 'immutable_datetime',
            'decision_deadline' => 'immutable_datetime',
            'recovery_deadline' => 'immutable_datetime',
            'confirmed_at' => 'immutable_datetime',
            'cancelled_at' => 'immutable_datetime',
            'version' => 'integer',
        ];
    }

    /** @return BelongsTo<Unit, $this> */
    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    /** @return BelongsTo<Guest, $this> */
    public function guest(): BelongsTo
    {
        return $this->belongsTo(Guest::class);
    }

    /** @return BelongsTo<Quote, $this> */
    public function quote(): BelongsTo
    {
        return $this->belongsTo(Quote::class);
    }

    /** @return HasMany<ReservationPriceLine, $this> */
    public function priceLines(): HasMany
    {
        return $this->hasMany(ReservationPriceLine::class)->orderBy('position');
    }

    /** @return HasMany<ReservationChange, $this> */
    public function changes(): HasMany
    {
        return $this->hasMany(ReservationChange::class);
    }

    /** @return HasMany<Payment, $this> */
    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }
}
