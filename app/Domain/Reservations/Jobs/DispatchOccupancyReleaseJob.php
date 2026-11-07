<?php

namespace App\Domain\Reservations\Jobs;

use App\Domain\Inventory\InventoryService;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Dispatcher del rilascio delle occupazioni, ogni minuto (routes/console.php; 05 "Rilascio delle
 * occupazioni con sessione"). Con app_admin_ops legge solo gli ID dei tenant che hanno occupazioni
 * temporanee scadute, poi accoda un OccupancyReleaseJob per tenant, che lavora con app_runtime e
 * contesto tenant (08 "Job cross-tenant").
 */
final class DispatchOccupancyReleaseJob implements ShouldQueue
{
    use Queueable;

    public function handle(DatabaseManager $db): void
    {
        $tenants = InventoryService::tenantsWithExpiredTemporary(
            $db->connection((string) config('payments.release_dispatcher_connection')),
            CarbonImmutable::now(),
            OccupancyReleaseJob::REASONS,
        );

        foreach ($tenants as $tenantId) {
            OccupancyReleaseJob::dispatch($tenantId);
        }
    }
}
