<?php

use App\Domain\Tenancy\Exceptions\InvalidTenantId;
use App\Domain\Tenancy\Exceptions\TenantContextConflict;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
 * Base di I5 (08, "Test di isolamento"): i test girano come app_runtime su una tabella sonda con RLS
 * forzata, creata da app_migrator e rimossa a fine test. Non è una tabella del dominio.
 */

const TENANT_A = '0193a1b2-0000-7000-8000-00000000000a';
const TENANT_B = '0193a1b2-0000-7000-8000-00000000000b';

beforeEach(function () {
    createTenancyProbeTable();
    $this->beforeApplicationDestroyed(fn () => dropTenancyProbeTable());
});

function currentTenantSetting(): ?string
{
    return DB::selectOne('select app_current_tenant()::text as tenant')->tenant;
}

it('esegue i test come app_runtime, senza superuser né BYPASSRLS', function () {
    $role = DB::selectOne(
        'select current_user as name, rolsuper, rolbypassrls from pg_roles where rolname = current_user'
    );

    expect($role->name)->toBe('app_runtime')
        ->and($role->rolsuper)->toBeFalse()
        ->and($role->rolbypassrls)->toBeFalse();
});

it('imposta app.tenant_id solo dentro run() e lo ripristina dopo', function () {
    $context = app(TenantContext::class);

    expect(currentTenantSetting())->toBeNull()
        ->and($context->hasTenant())->toBeFalse();

    $inside = $context->run(TENANT_A, function () use ($context) {
        expect($context->currentTenantId())->toBe(TENANT_A);

        return currentTenantSetting();
    });

    expect($inside)->toBe(TENANT_A)
        ->and(currentTenantSetting())->toBeNull()
        ->and($context->hasTenant())->toBeFalse();
});

it('usa una transazione con SET LOCAL, mai un SET di sessione', function () {
    $context = app(TenantContext::class);
    $levelOutside = DB::transactionLevel();

    $levelInside = $context->run(TENANT_A, fn () => DB::transactionLevel());

    expect($levelInside)->toBe($levelOutside + 1);

    // Dopo il run il valore non sopravvive nella sessione.
    expect(DB::selectOne("select current_setting('app.tenant_id', true) as v")->v)->toBeIn([null, '']);
});

it('in una transazione di primo livello SET LOCAL scade al commit', function () {
    // Connessione separata, fuori dalla transazione dei test: è il caso reale di richieste e job.
    config(['database.connections.pgsql_toplevel' => config('database.connections.pgsql')]);
    DB::setDefaultConnection('pgsql_toplevel');

    try {
        $connection = DB::connection('pgsql_toplevel');
        expect($connection->transactionLevel())->toBe(0);

        $inside = (new TenantContext(app('db')))->run(TENANT_A, fn () => [
            $connection->transactionLevel(),
            $connection->selectOne('select app_current_tenant()::text as t')->t,
        ]);

        expect($inside)->toBe([1, TENANT_A])
            ->and($connection->transactionLevel())->toBe(0)
            ->and($connection->selectOne("select current_setting('app.tenant_id', true) as v")->v)->toBeIn([null, '']);
    } finally {
        DB::setDefaultConnection('pgsql');
        DB::purge('pgsql_toplevel');
    }
});

it('rifiuta un tenant_id che non è un UUID e non tocca il database', function () {
    app(TenantContext::class)->run("x'; set role postgres; --", fn () => null);
})->throws(InvalidTenantId::class);

it('rifiuta un run annidato con un tenant diverso', function () {
    $context = app(TenantContext::class);

    $context->run(TENANT_A, fn () => $context->run(TENANT_B, fn () => null));
})->throws(TenantContextConflict::class);

it('consente un run annidato con lo stesso tenant', function () {
    $context = app(TenantContext::class);

    $value = $context->run(TENANT_A, fn () => $context->run(TENANT_A, fn () => currentTenantSetting()));

    expect($value)->toBe(TENANT_A);
});

it('senza contesto app_runtime non vede né scrive righe', function () {
    seedProbeRow(TENANT_A, 'a');

    expect(DB::table('tenancy_probe')->count())->toBe(0);

    expect(fn () => DB::table('tenancy_probe')->insert([
        'id' => (string) Str::uuid7(), 'tenant_id' => TENANT_A, 'label' => 'senza contesto',
    ]))->toThrow(QueryException::class);
});

it('con il contesto del tenant A vede solo le righe di A', function () {
    seedProbeRow(TENANT_A, 'a');
    seedProbeRow(TENANT_B, 'b');

    $labels = app(TenantContext::class)->run(
        TENANT_A,
        fn () => DB::table('tenancy_probe')->pluck('label')->all()
    );

    expect($labels)->toBe(['a']);
});

it('sotto il contesto di A rifiuta un insert con il tenant_id di B', function () {
    app(TenantContext::class)->run(TENANT_A, fn () => DB::table('tenancy_probe')->insert([
        'id' => (string) Str::uuid7(), 'tenant_id' => TENANT_B, 'label' => 'intrusa',
    ]));
})->throws(QueryException::class, 'row-level security');

it('un errore dentro run() annulla la transazione e lascia il contesto pulito', function () {
    $context = app(TenantContext::class);

    try {
        $context->run(TENANT_A, function () {
            DB::table('tenancy_probe')->insert([
                'id' => (string) Str::uuid7(), 'tenant_id' => TENANT_A, 'label' => 'annullata',
            ]);

            throw new RuntimeException('errore applicativo');
        });
    } catch (RuntimeException) {
    }

    expect($context->hasTenant())->toBeFalse()
        ->and(currentTenantSetting())->toBeNull()
        ->and($context->run(TENANT_A, fn () => DB::table('tenancy_probe')->count()))->toBe(0);
});
