<?php

namespace App\Domain\Privacy\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Dispatcher notturno del job di conservazione (7.2, 09 "Conservazione"): accoda le categorie globali
 * (una volta per esecuzione) e, con app_admin_ops che legge solo gli ID, un job per ogni tenant
 * (anche CLOSED: restano le righe di audit della categoria F).
 */
final class DispatchRetentionJob implements ShouldQueue
{
    use Queueable;

    public function handle(DatabaseManager $db): void
    {
        RunGlobalRetentionJob::dispatch();

        $rows = $db->connection((string) config('payments.release_dispatcher_connection'))
            ->select('select id::text as tenant_id from tenants order by id');

        foreach ($rows as $row) {
            RunTenantRetentionJob::dispatch((string) $row->tenant_id);
        }
    }
}
