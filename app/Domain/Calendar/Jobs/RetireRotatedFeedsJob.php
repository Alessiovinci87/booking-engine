<?php

namespace App\Domain\Calendar\Jobs;

use App\Domain\Calendar\IcsFeedLifecycle;
use App\Domain\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** Feed FROZEN da rotazione di un tenant scaduti dopo 7 giorni → RETIRED (06). */
final class RetireRotatedFeedsJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $uniqueFor = 3600;

    public function __construct(public readonly string $tenantId) {}

    public function uniqueId(): string
    {
        return $this->tenantId;
    }

    public function handle(TenantContext $tenants, IcsFeedLifecycle $lifecycle): void
    {
        $tenants->run($this->tenantId, fn () => $lifecycle->retireExpiredRotations(CarbonImmutable::now()));
    }
}
