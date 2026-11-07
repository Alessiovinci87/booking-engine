<?php

namespace App\Domain\Tenancy\Offboarding\Jobs;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Dispatcher giornaliero dell'uscita del cliente (7.1; 06 punti 4–5, 09): con app_admin_ops legge solo gli
 * ID dei tenant in OFFBOARDING o con export scaduti e accoda un job per tenant con app_runtime.
 */
final class DispatchOffboardingChecksJob implements ShouldQueue
{
    use Queueable;

    public function handle(DatabaseManager $db): void
    {
        $connection = $db->connection((string) config('payments.release_dispatcher_connection'));

        $rows = $connection->select(
            "select id::text as tenant_id from tenants where status = 'OFFBOARDING'
             union
             select distinct tenant_id::text from tenant_exports where status = 'READY' and expires_at <= ?
             limit 1000",
            [CarbonImmutable::now()->toIso8601ZuluString('microsecond')],
        );

        foreach ($rows as $row) {
            RunOffboardingChecksJob::dispatch((string) $row->tenant_id);
        }
    }
}
