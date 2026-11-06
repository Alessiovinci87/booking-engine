<?php

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/*
 * ADR-0009 (2d): payment_events è append-only per app_runtime, come audit_log.
 * PostgreSQL controlla i privilegi prima di cercare le righe: basta una UPDATE o DELETE senza righe.
 */

it('app_runtime ha solo SELECT e INSERT su payment_events', function () {
    $privilege = fn (string $p): bool => DB::selectOne(
        "select has_table_privilege('app_runtime', 'public.payment_events', ?) as g", [$p]
    )->g;

    expect($privilege('SELECT'))->toBeTrue()
        ->and($privilege('INSERT'))->toBeTrue()
        ->and($privilege('UPDATE'))->toBeFalse()
        ->and($privilege('DELETE'))->toBeFalse()
        ->and($privilege('TRUNCATE'))->toBeFalse();
});

it('app_runtime non modifica un evento di pagamento', function () {
    $tenant = createTenantRow();

    inTenant($tenant, fn () => DB::table('payment_events')->where('id', uuid7())->update(['amount_cents' => 1]));
})->throws(QueryException::class, 'permission denied');

it('app_runtime non cancella un evento di pagamento', function () {
    $tenant = createTenantRow();

    inTenant($tenant, fn () => DB::table('payment_events')->where('id', uuid7())->delete());
})->throws(QueryException::class, 'permission denied');

it('app_admin_ops non scrive su payment_events', function () {
    expect(DB::selectOne("select has_table_privilege('app_admin_ops', 'public.payment_events', 'INSERT') as g")->g)->toBeFalse();
});
