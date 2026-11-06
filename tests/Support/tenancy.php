<?php

use App\Domain\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
 * Tabella sonda per i test dell'isolamento: creata da app_migrator (proprietario, come una migration)
 * con RLS abilitata e forzata e la policy fail-closed di 08, rimossa alla fine di ogni test.
 * I test la usano come app_runtime.
 */

function createTenancyProbeTable(): void
{
    $migrator = DB::connection('pgsql_migrator');

    $migrator->statement('DROP TABLE IF EXISTS tenancy_probe');
    $migrator->statement(
        'CREATE TABLE tenancy_probe (id uuid PRIMARY KEY, tenant_id uuid NOT NULL, label text NOT NULL)'
    );
    $migrator->statement('ALTER TABLE tenancy_probe ENABLE ROW LEVEL SECURITY');
    $migrator->statement('ALTER TABLE tenancy_probe FORCE ROW LEVEL SECURITY');
    $migrator->statement(
        'CREATE POLICY tenant_isolation ON tenancy_probe '
        .'USING (tenant_id = app_current_tenant()) WITH CHECK (tenant_id = app_current_tenant())'
    );
}

function dropTenancyProbeTable(): void
{
    DB::connection('pgsql_migrator')->statement('DROP TABLE IF EXISTS tenancy_probe');
}

function seedProbeRow(string $tenantId, string $label): void
{
    app(TenantContext::class)->run($tenantId, fn () => DB::table('tenancy_probe')->insert([
        'id' => (string) Str::uuid7(),
        'tenant_id' => $tenantId,
        'label' => $label,
    ]));
}
