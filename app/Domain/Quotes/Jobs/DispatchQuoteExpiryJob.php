<?php

namespace App\Domain\Quotes\Jobs;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Dispatcher della scadenza dei preventivi (ogni 5 minuti): con app_admin_ops legge solo gli ID dei
 * tenant con preventivi DRAFT o SENT scaduti e accoda un job per tenant. L'accettazione verifica
 * comunque la scadenza da sé (I8): il job serve allo stato mostrato nel pannello.
 */
final class DispatchQuoteExpiryJob implements ShouldQueue
{
    use Queueable;

    public function handle(DatabaseManager $db): void
    {
        $connection = $db->connection((string) config('payments.release_dispatcher_connection'));

        $rows = $connection->select(
            "select distinct tenant_id::text as tenant_id from quotes where status in ('DRAFT', 'SENT') and expires_at <= ? limit 500",
            [CarbonImmutable::now()->toIso8601ZuluString('microsecond')],
        );

        foreach ($rows as $row) {
            ExpireQuotesJob::dispatch((string) $row->tenant_id);
        }
    }
}
