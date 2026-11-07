<?php

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/*
 * Ruoli PostgreSQL di 08 ("Livello database (RLS)"): app_migrator proprietario, app_runtime senza
 * privilegi speciali e solo DML, app_admin_ops in sola lettura cross-tenant.
 */

function roleAttributes(string $role): object
{
    return DB::selectOne(
        'select rolsuper, rolbypassrls, rolcreatedb, rolcreaterole, rolcanlogin from pg_roles where rolname = ?',
        [$role]
    );
}

it('la connessione predefinita usa app_runtime e quella delle migration app_migrator', function () {
    expect(config('database.default'))->toBe('pgsql')
        ->and(DB::selectOne('select current_user as u')->u)->toBe('app_runtime')
        ->and(DB::connection('pgsql_migrator')->selectOne('select current_user as u')->u)->toBe('app_migrator')
        ->and(DB::connection('pgsql_admin_ops')->selectOne('select current_user as u')->u)->toBe('app_admin_ops');
});

it('app_runtime è NOSUPERUSER e NOBYPASSRLS, senza creazione di database o ruoli', function () {
    $runtime = roleAttributes('app_runtime');

    expect($runtime->rolcanlogin)->toBeTrue()
        ->and($runtime->rolsuper)->toBeFalse()
        ->and($runtime->rolbypassrls)->toBeFalse()
        ->and($runtime->rolcreatedb)->toBeFalse()
        ->and($runtime->rolcreaterole)->toBeFalse();
});

it('app_migrator non è superuser e non scavalca RLS', function () {
    $migrator = roleAttributes('app_migrator');

    expect($migrator->rolsuper)->toBeFalse()
        ->and($migrator->rolbypassrls)->toBeFalse();
});

it('app_admin_ops scavalca RLS ma non è superuser', function () {
    $adminOps = roleAttributes('app_admin_ops');

    expect($adminOps->rolsuper)->toBeFalse()
        ->and($adminOps->rolbypassrls)->toBeTrue();
});

it('le tabelle appartengono ad app_migrator', function () {
    $owners = DB::table('pg_tables')->where('schemaname', 'public')->distinct()->pluck('tableowner')->all();

    expect($owners)->toBe(['app_migrator']);
});

it('app_runtime ha solo DML sulle tabelle create da app_migrator', function () {
    $privilege = fn (string $privilege): bool => DB::selectOne(
        'select has_table_privilege(?, ?, ?) as granted',
        ['app_runtime', 'public.migrations', $privilege]
    )->granted;

    expect($privilege('SELECT'))->toBeTrue()
        ->and($privilege('INSERT'))->toBeTrue()
        ->and($privilege('UPDATE'))->toBeTrue()
        ->and($privilege('DELETE'))->toBeTrue()
        ->and($privilege('TRUNCATE'))->toBeFalse()
        ->and($privilege('REFERENCES'))->toBeFalse()
        ->and($privilege('TRIGGER'))->toBeFalse();
});

it('app_runtime non può creare tabelle', function () {
    DB::statement('create table runtime_should_not_create (id int)');
})->throws(QueryException::class, 'permission denied');

it('app_runtime non può modificare né eliminare tabelle', function () {
    DB::statement('alter table migrations add column runtime_col int');
})->throws(QueryException::class, 'must be owner');

it('app_admin_ops può leggere ma non scrivere', function () {
    $adminOps = DB::connection('pgsql_admin_ops');

    expect($adminOps->table('migrations')->count())->toBeGreaterThan(0);

    $adminOps->table('migrations')->insert(['migration' => 'admin_ops_should_not_write', 'batch' => 0]);
})->throws(QueryException::class, 'permission denied');

it('app_retention non scavalca RLS e ha solo SELECT e DELETE sulle tabelle previste (7.1, 7.2)', function () {
    $retention = roleAttributes('app_retention');
    $privilege = fn (string $table, string $privilege): bool => DB::selectOne(
        'select has_table_privilege(?, ?, ?) as granted',
        ['app_retention', 'public.'.$table, $privilege]
    )->granted;

    expect($retention->rolsuper)->toBeFalse()
        ->and($retention->rolbypassrls)->toBeFalse()
        ->and($retention->rolcreatedb)->toBeFalse()
        ->and($retention->rolcreaterole)->toBeFalse();

    foreach (['reservations', 'guests', 'audit_log', 'tenant_exports', 'users', 'user_password_resets'] as $table) {
        expect($privilege($table, 'SELECT'))->toBeTrue("SELECT su {$table}")
            ->and($privilege($table, 'DELETE'))->toBeTrue("DELETE su {$table}")
            ->and($privilege($table, 'INSERT'))->toBeFalse("INSERT su {$table}")
            ->and($privilege($table, 'UPDATE'))->toBeFalse("UPDATE su {$table}")
            ->and($privilege($table, 'TRUNCATE'))->toBeFalse("TRUNCATE su {$table}");
    }

    foreach (['tenants', 'webhook_events', 'retention_policies', 'migrations'] as $table) {
        expect($privilege($table, 'DELETE'))->toBeFalse("DELETE su {$table}");
    }
});
