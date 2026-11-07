<?php

namespace App\Domain\Communications\Alerts\Jobs;

use App\Domain\Communications\Alerts\AlertSignal;
use App\Domain\Communications\Alerts\OperationalAlerts;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Coda in ritardo (08: "job in attesa > 5 min"), ogni 5 minuti con app_admin_ops: conta i messaggi
 * dell'outbox pronti da più di `alerts.queue_delay_minutes` e mai consegnati. Il dispatcher
 * dell'outbox gira ogni minuto, quindi un ritardo vuol dire coda ferma o worker spenti. Solo
 * conteggi (nessun ID di tenant nel messaggio). Horizon misura la stessa cosa in staging e
 * produzione (6.4).
 */
final class CheckQueueDelayJob implements ShouldQueue
{
    use Queueable;

    public function handle(DatabaseManager $db, OperationalAlerts $alerts): void
    {
        $minutes = (int) config('alerts.queue_delay_minutes', 5);
        $row = $db->connection((string) config('alerts.connection'))->selectOne(
            "select count(*) as delayed, min(available_at) as oldest from outbox_messages
             where status = 'PENDING' and available_at < ?",
            [CarbonImmutable::now()->subMinutes($minutes)->toIso8601ZuluString('microsecond')],
        );

        if ($row !== null && (int) $row->delayed > 0) {
            $alerts->raise(AlertSignal::QUEUE_DELAYED, [
                'delayed_messages' => (int) $row->delayed,
                'oldest_available_at' => CarbonImmutable::parse((string) $row->oldest)->toIso8601ZuluString(),
            ], key: 'outbox');
        }
    }
}
