<?php

namespace App\Domain\Communications\Alerts\Jobs;

use App\Domain\Communications\Alerts\AlertSignal;
use App\Domain\Communications\Alerts\OperationalAlerts;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Riepilogo giornaliero interno dei calendari `UNHEALTHY` (08: "email al gestore; riepilogo
 * giornaliero interno"), con app_admin_ops. Il gestore ha già ricevuto la sua email dall'outbox
 * (`calendar.sync_unhealthy`); qui la piattaforma vede quante strutture hanno un calendario fermo.
 * Solo conteggi e ID dei tenant, nessun URL di calendario (cifrati, 08).
 */
final class UnhealthyCalendarsSummaryJob implements ShouldQueue
{
    use Queueable;

    public function handle(DatabaseManager $db, OperationalAlerts $alerts): void
    {
        $rows = $db->connection((string) config('alerts.connection'))->select(
            "select tenant_id::text as tenant_id, count(*) as calendars from external_calendars
             where status = 'UNHEALTHY' and not demo group by tenant_id order by count(*) desc limit 50",
        );

        if ($rows === []) {
            return;
        }

        $context = ['tenants' => count($rows), 'calendars' => array_sum(array_map(fn (object $r): int => (int) $r->calendars, $rows))];

        foreach ($rows as $row) {
            $context['tenant '.$row->tenant_id] = (int) $row->calendars;
        }

        $alerts->raise(AlertSignal::CALENDARS_UNHEALTHY_DAILY, $context, key: 'daily:'.CarbonImmutable::now()->toDateString());
    }
}
