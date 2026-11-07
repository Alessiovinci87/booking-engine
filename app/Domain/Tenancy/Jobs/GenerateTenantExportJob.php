<?php

namespace App\Domain\Tenancy\Jobs;

use App\Domain\Tenancy\Enums\TenantExportStatus;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\Models\TenantExport;
use App\Domain\Tenancy\Offboarding\TenantExportBuilder;
use App\Domain\Tenancy\Outbox\Outbox;
use App\Domain\Tenancy\StateMachines\TenantExportStateMachine;
use App\Domain\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Filesystem\Factory as FilesystemFactory;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Http\File;
use Throwable;

/**
 * Export completo del tenant, asincrono (7.1; 07 POST /admin/tenant/export): ZIP su disco privato, stato
 * READY per 30 giorni ed email al gestore tramite outbox. Al terzo tentativo fallito: FAILED.
 */
final class GenerateTenantExportJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(public readonly string $tenantId, public readonly string $exportId) {}

    public function handle(TenantContext $tenants, TenantExportBuilder $builder, TenantExportStateMachine $machine, FilesystemFactory $files, Outbox $outbox): void
    {
        $tenants->run($this->tenantId, function () use ($builder, $machine, $files, $outbox): void {
            $export = TenantExport::query()->lockForUpdate()->find($this->exportId);

            if ($export === null || $export->status !== TenantExportStatus::PENDING) {
                return;
            }

            $tenant = Tenant::query()->firstOrFail();
            $built = $builder->buildZip($tenant, CarbonImmutable::now());

            try {
                $size = (int) filesize($built['path']);
                $directory = 'tenant-exports/'.$this->tenantId;
                $name = $export->id.'.zip';
                $files->disk((string) config('offboarding.disk'))->putFileAs($directory, new File($built['path']), $name);
            } finally {
                @unlink($built['path']);
            }

            $machine->ready($export, $directory.'/'.$name, $size, $built['future_reservations']);
            $outbox->enqueue('tenant.export_ready', 'tenant.export_ready:'.$export->id, ['tenant_export_id' => $export->id]);
        });
    }

    public function failed(?Throwable $exception): void
    {
        app(TenantContext::class)->run($this->tenantId, function (): void {
            $export = TenantExport::query()->lockForUpdate()->find($this->exportId);

            if ($export !== null && $export->status === TenantExportStatus::PENDING) {
                app(TenantExportStateMachine::class)->fail($export);
            }
        });
    }
}
