<?php

namespace App\Domain\Calendar\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Dispatcher della salute della sincronizzazione (06; 5.6): ogni 5 minuti, con app_admin_ops, legge gli
 * ID dei tenant con calendari importati non disattivati o unità ancora segnate e accoda un job per tenant.
 */
final class DispatchSyncHealthJob implements ShouldQueue
{
    use Queueable;

    public function handle(DatabaseManager $db): void
    {
        $connection = $db->connection((string) config('payments.release_dispatcher_connection'));

        $rows = $connection->select(
            "select tenant_id::text as tenant_id from external_calendars where status <> 'DISABLED' and not demo
             union select tenant_id::text from units where sync_health <> 'NONE' limit 1000",
        );

        foreach ($rows as $row) {
            EvaluateSyncHealthJob::dispatch((string) $row->tenant_id);
        }
    }
}
