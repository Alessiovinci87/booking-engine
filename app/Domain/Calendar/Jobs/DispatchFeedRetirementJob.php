<?php

namespace App\Domain\Calendar\Jobs;

use App\Domain\Calendar\IcsFeedLifecycle;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Dispatcher orario del ritiro dei feed ruotati (06 "Ciclo di vita dei feed"): con app_admin_ops legge
 * solo gli ID dei tenant con feed FROZEN da rotazione oltre i 7 giorni e accoda un job per tenant.
 */
final class DispatchFeedRetirementJob implements ShouldQueue
{
    use Queueable;

    public function handle(DatabaseManager $db): void
    {
        $connection = $db->connection((string) config('payments.release_dispatcher_connection'));
        $cutoff = CarbonImmutable::now()->subDays(IcsFeedLifecycle::ROTATION_GRACE_DAYS);

        $rows = $connection->select(
            "select distinct tenant_id::text as tenant_id from ics_feeds where status = 'FROZEN' and rotated_at is not null and rotated_at <= ? limit 500",
            [$cutoff->toIso8601ZuluString('microsecond')],
        );

        foreach ($rows as $row) {
            RetireRotatedFeedsJob::dispatch((string) $row->tenant_id);
        }
    }
}
