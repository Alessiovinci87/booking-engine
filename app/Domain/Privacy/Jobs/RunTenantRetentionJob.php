<?php

namespace App\Domain\Privacy\Jobs;

use App\Domain\Privacy\RetentionService;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** Conservazione per un tenant: simulazione o esecuzione per categoria (7.2). */
final class RunTenantRetentionJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 900;

    public int $uniqueFor = 3600;

    public function __construct(public readonly string $tenantId) {}

    public function uniqueId(): string
    {
        return $this->tenantId;
    }

    public function handle(RetentionService $retention): void
    {
        $retention->runTenant($this->tenantId, CarbonImmutable::now());
    }
}
