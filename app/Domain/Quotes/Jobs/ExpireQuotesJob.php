<?php

namespace App\Domain\Quotes\Jobs;

use App\Domain\Quotes\QuoteService;
use App\Domain\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** Preventivi DRAFT e SENT scaduti di un tenant → EXPIRED (03, sezione 4). */
final class ExpireQuotesJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $uniqueFor = 600;

    public function __construct(public readonly string $tenantId) {}

    public function uniqueId(): string
    {
        return $this->tenantId;
    }

    public function handle(TenantContext $tenants, QuoteService $quotes): void
    {
        $tenants->run($this->tenantId, fn () => $quotes->expireDue(CarbonImmutable::now()));
    }
}
