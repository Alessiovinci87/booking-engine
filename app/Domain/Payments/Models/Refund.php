<?php

namespace App\Domain\Payments\Models;

use App\Domain\Payments\Enums\RefundMethod;
use App\Domain\Payments\Enums\RefundStatus;
use App\Domain\Privacy\PersonalData;
use App\Domain\Tenancy\Models\Concerns\GuardsStatusWrites;
use App\Domain\Tenancy\Models\Concerns\StatusGuardedBuilder;
use App\Domain\Tenancy\Models\TenantModel;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\UseEloquentBuilder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Rimborso (02, 05). Macchina a stati in 03, sezione 3: status si scrive solo dalle StateMachines.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $payment_id
 * @property int $amount_cents
 * @property RefundMethod $method
 * @property string|null $reason
 * @property string|null $stripe_refund_id
 * @property RefundStatus $status
 * @property int $attempts
 * @property CarbonImmutable|null $next_attempt_at
 * @property string|null $requested_by
 * @property CarbonImmutable|null $completed_at
 */
#[UseEloquentBuilder(StatusGuardedBuilder::class)]
#[PersonalData('reason')]
class Refund extends TenantModel
{
    use GuardsStatusWrites;

    protected function casts(): array
    {
        return [
            'amount_cents' => 'integer',
            'method' => RefundMethod::class,
            'status' => RefundStatus::class,
            'attempts' => 'integer',
            'next_attempt_at' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Payment, $this> */
    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }
}
