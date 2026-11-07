<?php

namespace App\Console\Commands;

use App\Domain\Tenancy\Support\SupportAccessService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Chiusura anticipata, dalla piattaforma, degli accessi di supporto attivi di un tenant (7.5).
 */
final class SupportCloseCommand extends Command
{
    protected $signature = 'support:close {tenant : Slug del tenant}';

    protected $description = 'Chiude gli accessi di supporto attivi di un tenant';

    public function handle(SupportAccessService $support): int
    {
        $tenantId = DB::selectOne('select app_tenant_by_slug(?) as id', [(string) $this->argument('tenant')])?->id;

        if (! is_string($tenantId)) {
            $this->error(__('tenancy.support.unknown_tenant'));

            return self::FAILURE;
        }

        $this->info(__('tenancy.support.closed', ['count' => $support->close($tenantId)]));

        return self::SUCCESS;
    }
}
