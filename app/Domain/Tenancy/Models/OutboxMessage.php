<?php

namespace App\Domain\Tenancy\Models;

use App\Domain\Tenancy\Enums\OutboxStatus;
use App\Domain\Tenancy\Models\Concerns\GuardsStatusWrites;
use App\Domain\Tenancy\Models\Concerns\StatusGuardedBuilder;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\UseEloquentBuilder;

/**
 * Messaggio in uscita (02, 03 "Regole generali"): scritto nella stessa transazione della modifica che
 * lo genera e consegnato in modo asincrono dal dispatcher, con retry e dedupe_key unico per tenant.
 * I messaggi inviati o falliti si cancellano dopo la durata della categoria G (09, job di conservazione 7.2).
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $type
 * @property array<string, mixed>|null $payload
 * @property string $dedupe_key
 * @property OutboxStatus $status
 * @property int $attempts
 * @property CarbonImmutable $available_at
 * @property CarbonImmutable|null $sent_at
 * @property CarbonImmutable|null $failed_at
 * @property string|null $last_error
 * @property CarbonImmutable|null $payload_purged_at
 */
#[UseEloquentBuilder(StatusGuardedBuilder::class)]
class OutboxMessage extends TenantModel
{
    use GuardsStatusWrites;

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'status' => OutboxStatus::class,
            'attempts' => 'integer',
            'available_at' => 'immutable_datetime',
            'sent_at' => 'immutable_datetime',
            'failed_at' => 'immutable_datetime',
            'payload_purged_at' => 'immutable_datetime',
        ];
    }

    /** ID nel payload (solo identificativi, 02): stringa non vuota oppure null, per le ricerche per chiave. */
    public function payloadId(string $key): ?string
    {
        $value = $this->payload[$key] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }
}
