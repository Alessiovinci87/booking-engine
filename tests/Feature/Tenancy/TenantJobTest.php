<?php

use App\Domain\Tenancy\Jobs\InteractsWithTenant;
use App\Domain\Tenancy\Jobs\TenantAwareJob;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;

final class ProbeTenantJob implements ShouldQueue, TenantAwareJob
{
    use Dispatchable, InteractsWithQueue, InteractsWithTenant, Queueable, SerializesModels;

    public static ?string $seenTenant = null;

    public function __construct(string $tenantId)
    {
        $this->tenantId = $tenantId;
    }

    public function handle(): void
    {
        self::$seenTenant = DB::selectOne('select app_current_tenant()::text as tenant')->tenant;
    }
}

beforeEach(fn () => ProbeTenantJob::$seenTenant = null);

it('serializza il tenant_id con il job', function () {
    $job = unserialize(serialize(new ProbeTenantJob('0193a1b2-0000-7000-8000-00000000000a')));

    expect($job->tenantId())->toBe('0193a1b2-0000-7000-8000-00000000000a');
});

it('esegue il job dentro il contesto del suo tenant', function () {
    config(['queue.default' => 'sync']);

    ProbeTenantJob::dispatch('0193a1b2-0000-7000-8000-00000000000a');

    expect(ProbeTenantJob::$seenTenant)->toBe('0193a1b2-0000-7000-8000-00000000000a')
        ->and(DB::selectOne('select app_current_tenant()::text as tenant')->tenant)->toBeNull();
});
