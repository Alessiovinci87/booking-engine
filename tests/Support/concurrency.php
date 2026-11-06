<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

/*
 * Test di concorrenza con processi PHP veri (AC5, 11 attività 3.1 e 3.4). I processi figli non vedono
 * la transazione di RefreshDatabase: i dati di partenza si scrivono e si confermano su una connessione
 * separata, e alla fine si cancellano con app_migrator (proprietario delle tabelle, anche dell'audit
 * log append-only per app_runtime). RLS è forzata anche per il proprietario: entrambe le connessioni
 * impostano app.tenant_id a livello di sessione.
 */

function committedConnection(string $base, string $tenantId): string
{
    $name = $base.'_committed';
    config(["database.connections.{$name}" => config("database.connections.{$base}")]);
    DB::purge($name);
    DB::connection($name)->select("select set_config('app.tenant_id', ?, false)", [$tenantId]);

    return $name;
}

/**
 * Tenant, struttura e unità confermati nel database, visibili ai processi figli.
 *
 * @return array{tenant: string, property: string, unit: string}
 */
function committedTenantWithUnit(array $unitOverrides = []): array
{
    $tenant = uuid7();
    $property = uuid7();
    $unit = uuid7();
    $db = DB::connection(committedConnection('pgsql', $tenant));
    $now = now();

    $db->table('tenants')->insert(['id' => $tenant, 'name' => 'Concorrenza', 'slug' => 'conc-'.Str::lower(Str::random(10)), 'created_at' => $now, 'updated_at' => $now]);
    $db->table('properties')->insert(['id' => $property, 'tenant_id' => $tenant, 'name' => 'Casa', 'slug' => 'casa', 'created_at' => $now, 'updated_at' => $now]);
    $db->table('units')->insert(array_merge([
        'id' => $unit, 'tenant_id' => $tenant, 'property_id' => $property, 'name' => 'Unità',
        'base_occupancy' => 2, 'max_occupancy' => 4, 'created_at' => $now, 'updated_at' => $now,
    ], $unitOverrides));

    return ['tenant' => $tenant, 'property' => $property, 'unit' => $unit];
}

/** Cancella tutte le righe confermate del tenant, nell'ordine imposto dalle chiavi esterne. */
function deleteCommittedTenant(string $tenantId): void
{
    $db = DB::connection(committedConnection('pgsql_migrator', $tenantId));
    $tables = collect($db->select(
        "select table_name from information_schema.columns where table_schema = 'public' and column_name = 'tenant_id'"
    ))->pluck('table_name')->all();

    // Più passate: una riga referenziata si cancella al giro successivo.
    for ($pass = 0; $pass < 10 && $tables !== []; $pass++) {
        foreach ($tables as $index => $table) {
            try {
                $db->table($table)->where('tenant_id', $tenantId)->delete();

                if (! $db->table($table)->where('tenant_id', $tenantId)->exists()) {
                    unset($tables[$index]);
                }
            } catch (Throwable) {
                // ancora referenziata
            }
        }
    }

    $db->table('tenants')->where('id', $tenantId)->delete();
    DB::purge('pgsql_committed');
    DB::purge('pgsql_migrator_committed');
}

/**
 * Lancia $count processi PHP che eseguono lo script con gli stessi argomenti e partono insieme.
 * Restituisce l'output JSON di ciascuno.
 *
 * @param  list<string>  $arguments
 * @return list<array<string, mixed>>
 */
function runConcurrently(string $script, array $arguments, int $count): array
{
    $startAt = sprintf('%.3f', microtime(true) + 3.0);
    $env = [
        'APP_ENV' => 'testing',
        'DB_DATABASE' => (string) config('database.connections.pgsql.database'),
        'CACHE_STORE' => 'array',
        'SESSION_DRIVER' => 'array',
        'QUEUE_CONNECTION' => 'sync',
        'MAIL_MAILER' => 'array',
        'PAYMENT_GATEWAY' => 'fake',
    ];

    $processes = [];

    for ($i = 0; $i < $count; $i++) {
        $process = new Process([PHP_BINARY, base_path('tests/Support/concurrency/'.$script), $startAt, ...$arguments], base_path(), $env, null, 60);
        $process->start();
        $processes[] = $process;
    }

    $results = [];

    foreach ($processes as $process) {
        $process->wait();
        $line = trim((string) collect(explode("\n", trim($process->getOutput())))->last());
        $results[] = json_decode($line, true) ?? ['ok' => false, 'code' => 'PROCESS_ERROR', 'output' => mb_substr($process->getOutput().$process->getErrorOutput(), 0, 500)];
    }

    return $results;
}
