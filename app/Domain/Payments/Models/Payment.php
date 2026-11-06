<?php

namespace App\Domain\Payments\Models;

use App\Domain\Payments\Enums\CaptureMethod;
use App\Domain\Payments\Enums\OfflineMethod;
use App\Domain\Payments\Enums\PaymentPurpose;
use App\Domain\Payments\Enums\PaymentStatus;
use App\Domain\Reservations\Models\Reservation;
use App\Domain\Tenancy\Models\Concerns\GuardsStatusWrites;
use App\Domain\Tenancy\Models\Concerns\StatusGuardedBuilder;
use App\Domain\Tenancy\Models\TenantModel;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\UseEloquentBuilder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Tentativo di pagamento (02, 05). Macchina a stati in 03, sezione 2: status si scrive solo dalle
 * StateMachines.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $reservation_id
 * @property PaymentPurpose $purpose
 * @property int $amount_cents
 * @property CaptureMethod $capture_method
 * @property PaymentStatus $status
 * @property string|null $stripe_account_id
 * @property string|null $checkout_session_id
 * @property CarbonImmutable|null $checkout_expires_at
 * @property string|null $payment_intent_id
 * @property CarbonImmutable|null $capture_before
 * @property int $captured_cents
 * @property int $fee_cents
 * @property OfflineMethod|null $method_offline
 * @property string|null $failure_code
 */
#[UseEloquentBuilder(StatusGuardedBuilder::class)]
class Payment extends TenantModel
{
    use GuardsStatusWrites;

    protected function casts(): array
    {
        return [
            'purpose' => PaymentPurpose::class,
            'amount_cents' => 'integer',
            'capture_method' => CaptureMethod::class,
            'status' => PaymentStatus::class,
            'checkout_expires_at' => 'immutable_datetime',
            'capture_before' => 'immutable_datetime',
            'captured_cents' => 'integer',
            'fee_cents' => 'integer',
            'method_offline' => OfflineMethod::class,
        ];
    }

    /** @return BelongsTo<Reservation, $this> */
    public function reservation(): BelongsTo
    {
        return $this->belongsTo(Reservation::class);
    }

    /** @return HasMany<PaymentEvent, $this> */
    public function events(): HasMany
    {
        return $this->hasMany(PaymentEvent::class);
    }

    /** @return HasMany<Refund, $this> */
    public function refunds(): HasMany
    {
        return $this->hasMany(Refund::class);
    }
}
