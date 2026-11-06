<?php

namespace App\Domain\Payments\Models;

use App\Domain\Tenancy\Models\TenantModel;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Evento di un pagamento, append-only (02): del payload Stripe si salva solo l'hash (08).
 *
 * @property string $id
 * @property string $payment_id
 * @property string $type
 * @property int|null $amount_cents
 * @property string|null $stripe_object_id
 * @property CarbonImmutable $occurred_at
 * @property string|null $payload_hash
 */
class PaymentEvent extends TenantModel
{
    protected function casts(): array
    {
        return [
            'amount_cents' => 'integer',
            'occurred_at' => 'immutable_datetime',
        ];
    }

    public static function bootPaymentEvent(): void
    {
        static::updating(fn () => throw new LogicException('Gli eventi di pagamento sono append-only.'));
        static::deleting(fn () => throw new LogicException('Gli eventi di pagamento sono append-only.'));
    }

    /** @return BelongsTo<Payment, $this> */
    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }
}
