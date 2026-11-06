<?php

namespace App\Domain\Tenancy\Outbox;

use App\Domain\Tenancy\Exceptions\MissingTenantContext;
use App\Domain\Tenancy\Models\OutboxMessage;
use App\Domain\Tenancy\TenantContext;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Accodamento di un messaggio in uscita nella stessa transazione della modifica che lo genera (03).
 * Lo stesso dedupe_key nello stesso tenant produce un solo messaggio: la seconda chiamata restituisce
 * quello esistente senza interrompere la transazione (ON CONFLICT DO NOTHING).
 */
final class Outbox
{
    public function __construct(private readonly TenantContext $tenantContext) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public function enqueue(string $type, string $dedupeKey, array $payload = [], ?DateTimeInterface $availableAt = null): OutboxMessage
    {
        $tenantId = $this->tenantContext->currentTenantId();

        if ($tenantId === null) {
            throw MissingTenantContext::make();
        }

        $now = now();

        DB::table('outbox_messages')->insertOrIgnore([
            'id' => (string) Str::uuid7(),
            'tenant_id' => $tenantId,
            'type' => $type,
            'payload' => json_encode($payload, JSON_THROW_ON_ERROR),
            'dedupe_key' => $dedupeKey,
            'available_at' => $availableAt ?? $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return OutboxMessage::query()->where('dedupe_key', $dedupeKey)->firstOrFail();
    }
}
