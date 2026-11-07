<?php

namespace App\Domain\Tenancy\Offboarding\Jobs;

use App\Domain\Tenancy\Offboarding\OffboardingService;
use App\Domain\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** Per un tenant: export scaduti cancellati e ritiro automatico dei feed non più letti (06, 09). */
final class RunOffboardingChecksJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $uniqueFor = 3600;

    public function __construct(public readonly string $tenantId) {}

    public function uniqueId(): string
    {
        return $this->tenantId;
    }

    public function handle(TenantContext $tenants, OffboardingService $offboarding): void
    {
        $now = CarbonImmutable::now();

        $tenants->run($this->tenantId, function () use ($offboarding, $now): void {
            $offboarding->expireExports($now);
            $offboarding->retireUnreadFeeds($now);
        });
    }
}
