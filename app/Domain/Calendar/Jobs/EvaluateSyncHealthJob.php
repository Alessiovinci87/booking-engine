<?php

namespace App\Domain\Calendar\Jobs;

use App\Domain\Calendar\SyncHealthService;
use App\Domain\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** Salute della sincronizzazione dei calendari di un tenant (06 "Salute"). */
final class EvaluateSyncHealthJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $uniqueFor = 300;

    public function __construct(public readonly string $tenantId) {}

    public function uniqueId(): string
    {
        return $this->tenantId;
    }

    public function handle(TenantContext $tenants, SyncHealthService $health): void
    {
        $tenants->run($this->tenantId, fn () => $health->evaluateTenant(CarbonImmutable::now()));
    }
}
