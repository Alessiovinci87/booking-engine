<?php

namespace App\Domain\Tenancy\Outbox;

use Illuminate\Database\DatabaseManager;

/**
 * Legge gli ID dei messaggi pronti con app_admin_ops (BYPASSRLS, sola lettura). Nessun altro campo:
 * payload e dati del messaggio si leggono solo nel job, con app_runtime e contesto tenant (08).
 */
final class AdminOpsPendingOutboxMessages implements PendingOutboxMessages
{
    public function __construct(private readonly DatabaseManager $db) {}

    public function due(int $limit): array
    {
        $rows = $this->db->connection((string) config('outbox.dispatcher_connection'))->select(
            "select tenant_id::text as tenant_id, id::text as id from outbox_messages
             where status = 'PENDING' and available_at <= now()
             order by available_at
             limit ?",
            [$limit],
        );

        return array_map(fn (object $row): array => ['tenant_id' => $row->tenant_id, 'id' => $row->id], $rows);
    }
}
