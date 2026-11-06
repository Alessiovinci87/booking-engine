<?php

namespace App\Domain\Tenancy\Jobs;

use App\Domain\Tenancy\Outbox\PendingOutboxMessages;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Dispatcher dell'outbox, ogni minuto (routes/console.php): seleziona gli ID dei messaggi pronti di
 * tutti i tenant e accoda un DeliverOutboxMessageJob per messaggio (08 "Job cross-tenant").
 */
final class DispatchOutboxJob implements ShouldQueue
{
    use Queueable;

    public function handle(PendingOutboxMessages $pending): void
    {
        foreach ($pending->due((int) config('outbox.batch_size')) as $row) {
            DeliverOutboxMessageJob::dispatch($row['tenant_id'], $row['id']);
        }
    }
}
