<?php

namespace App\Domain\Tenancy\Outbox;

/**
 * Selezione cross-tenant dei messaggi pronti per la consegna (08: il dispatcher legge solo ID).
 */
interface PendingOutboxMessages
{
    /**
     * @return list<array{tenant_id: string, id: string}>
     */
    public function due(int $limit): array;
}
