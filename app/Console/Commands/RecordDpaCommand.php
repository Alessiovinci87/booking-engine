<?php

namespace App\Console\Commands;

use App\Domain\Privacy\DpaService;
use App\Domain\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Registra la firma del DPA art. 28 di un tenant (09 requisito 1, 6.3): la piattaforma lo fa dopo
 * aver ricevuto il documento firmato, prima dell'attivazione. Senza DPA la struttura non si pubblica.
 */
final class RecordDpaCommand extends Command
{
    protected $signature = 'tenant:dpa
        {slug : Slug del tenant}
        {dpa-version : Versione del DPA firmato (per esempio 2027-01)}
        {--signed-at= : Data della firma (AAAA-MM-GG, predefinita oggi)}';

    protected $description = 'Registra la firma del DPA (art. 28 GDPR) di un tenant';

    public function handle(TenantContext $tenants, DpaService $dpa): int
    {
        $tenantId = DB::selectOne('select app_tenant_by_slug(?) as id', [(string) $this->argument('slug')])->id;
        $version = trim((string) $this->argument('dpa-version'));

        if ($tenantId === null || $version === '' || mb_strlen($version) > 50) {
            $this->error(__('tenancy.dpa.invalid'));

            return self::FAILURE;
        }

        try {
            $signedAt = $this->option('signed-at') === null
                ? CarbonImmutable::now('UTC')->startOfDay()
                : CarbonImmutable::createFromFormat('!Y-m-d', (string) $this->option('signed-at'), 'UTC');
        } catch (Throwable) {
            $signedAt = false;
        }

        if (! $signedAt instanceof CarbonImmutable || $signedAt->isFuture()) {
            $this->error(__('tenancy.dpa.invalid_date'));

            return self::FAILURE;
        }

        $tenants->run((string) $tenantId, fn () => $dpa->record($version, $signedAt));
        $this->info(__('tenancy.dpa.done', ['version' => $version, 'date' => $signedAt->toDateString()]));

        return self::SUCCESS;
    }
}
