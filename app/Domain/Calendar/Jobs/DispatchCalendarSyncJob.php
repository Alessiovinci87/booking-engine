<?php

namespace App\Domain\Calendar\Jobs;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Dispatcher della sincronizzazione dei calendari importati (06 "Polling"; 5.2): ogni minuto, con
 * app_admin_ops, legge solo gli ID dei calendari non disattivati con next_poll_at scaduto (o mai
 * sincronizzati) e accoda un SyncExternalCalendarJob per calendario, che lavora con app_runtime e
 * contesto tenant (08 "Job cross-tenant"). Il jitter è già in next_poll_at.
 */
final class DispatchCalendarSyncJob implements ShouldQueue
{
    use Queueable;

    public const BATCH = 500;

    public function handle(DatabaseManager $db): void
    {
        $connection = $db->connection((string) config('payments.release_dispatcher_connection'));

        $rows = $connection->select(
            "select id::text as id, tenant_id::text as tenant_id from external_calendars
             where status <> 'DISABLED' and not demo and (next_poll_at is null or next_poll_at <= ?)
             order by next_poll_at nulls first limit ".self::BATCH,
            [CarbonImmutable::now()->toIso8601ZuluString('microsecond')],
        );

        foreach ($rows as $row) {
            SyncExternalCalendarJob::dispatch((string) $row->tenant_id, (string) $row->id);
        }
    }
}
