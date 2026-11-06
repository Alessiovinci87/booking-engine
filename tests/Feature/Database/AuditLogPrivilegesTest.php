<?php

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/*
 * Attività 1.2 (08 "Audit log"): audit_log è append-only per app_runtime (UPDATE e DELETE revocati).
 */

function insertAuditRow(?string $tenantId): string
{
    $id = uuid7();
    $row = [
        'id' => $id, 'tenant_id' => $tenantId, 'actor_type' => 'SYSTEM', 'action' => 'prova',
        'changes' => '{}', 'occurred_at' => now(),
    ];

    $tenantId === null
        ? DB::table('audit_log')->insert($row)
        : inTenant($tenantId, fn () => DB::table('audit_log')->insert($row));

    return $id;
}

it('app_runtime ha solo SELECT e INSERT su audit_log', function () {
    $privilege = fn (string $p): bool => DB::selectOne(
        "select has_table_privilege('app_runtime', 'public.audit_log', ?) as g", [$p]
    )->g;

    expect($privilege('SELECT'))->toBeTrue()
        ->and($privilege('INSERT'))->toBeTrue()
        ->and($privilege('UPDATE'))->toBeFalse()
        ->and($privilege('DELETE'))->toBeFalse()
        ->and($privilege('TRUNCATE'))->toBeFalse();
});

it('app_runtime non modifica una riga di audit', function () {
    $tenant = createTenantRow();
    $id = insertAuditRow($tenant);

    inTenant($tenant, fn () => DB::table('audit_log')->where('id', $id)->update(['action' => 'alterata']));
})->throws(QueryException::class, 'permission denied');

it('app_runtime non cancella una riga di audit', function () {
    $tenant = createTenantRow();
    $id = insertAuditRow($tenant);

    inTenant($tenant, fn () => DB::table('audit_log')->where('id', $id)->delete());
})->throws(QueryException::class, 'permission denied');

it('scrive eventi di piattaforma senza tenant solo fuori da un contesto tenant e non li rilegge', function () {
    insertAuditRow(null);

    expect(DB::table('audit_log')->count())->toBe(0);

    $tenant = createTenantRow();
    inTenant($tenant, fn () => DB::table('audit_log')->insert([
        'id' => uuid7(), 'tenant_id' => null, 'actor_type' => 'SYSTEM', 'action' => 'prova', 'occurred_at' => now(),
    ]));
})->throws(QueryException::class, 'row-level security');

it('non scrive una riga di audit di un tenant fuori dal suo contesto', function () {
    insertAuditRow(createTenantRow());
    DB::table('audit_log')->insert([
        'id' => uuid7(), 'tenant_id' => createTenantRow(), 'actor_type' => 'SYSTEM', 'action' => 'prova', 'occurred_at' => now(),
    ]);
})->throws(QueryException::class, 'row-level security');
