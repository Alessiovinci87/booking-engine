<?php

use App\Domain\Tenancy\TenantResolver;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

beforeEach(function () {
    Route::middleware('tenant')->get('/_test/tenant', fn () => response()->json([
        'tenant' => DB::selectOne('select app_current_tenant()::text as tenant')->tenant,
        'level' => DB::transactionLevel(),
    ]));
});

it('risponde 404 quando il tenant non è risolvibile (risolutore predefinito)', function () {
    $this->getJson('/_test/tenant')->assertNotFound();
});

it('gestisce la richiesta in una transazione con il tenant risolto', function () {
    app()->instance(TenantResolver::class, new class implements TenantResolver
    {
        public function resolve(Request $request): ?string
        {
            return '0193a1b2-0000-7000-8000-00000000000a';
        }
    });

    $outside = DB::transactionLevel();

    $this->getJson('/_test/tenant')
        ->assertOk()
        ->assertJson(['tenant' => '0193a1b2-0000-7000-8000-00000000000a', 'level' => $outside + 1]);

    expect(DB::selectOne('select app_current_tenant()::text as tenant')->tenant)->toBeNull();
});

it('ignora un tenant_id inviato dal client', function () {
    $this->getJson('/_test/tenant?tenant_id=0193a1b2-0000-7000-8000-00000000000b', [
        'X-Tenant-Id' => '0193a1b2-0000-7000-8000-00000000000b',
    ])->assertNotFound();
});
