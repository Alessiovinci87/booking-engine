<?php

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/*
 * Funzioni SECURITY DEFINER per le ricerche che precedono il tenant (login, slug pubblico): proprietà
 * di app_migrator, search_path fissato, solo ID in uscita, EXECUTE solo ad app_runtime.
 */

const LOOKUP_FUNCTIONS = ['app_user_tenants', 'app_tenant_by_slug'];

it('sono SECURITY DEFINER di app_migrator, con search_path fissato, e restituiscono solo UUID', function (string $name) {
    $fn = DB::selectOne(<<<'SQL'
        SELECT p.prosecdef, pg_get_userbyid(p.proowner) AS owner, p.proconfig, p.proretset,
               format_type(p.prorettype, NULL) AS return_type
        FROM pg_proc p JOIN pg_namespace n ON n.oid = p.pronamespace
        WHERE n.nspname = 'public' AND p.proname = ?
        SQL, [$name]);

    expect($fn)->not->toBeNull()
        ->and($fn->prosecdef)->toBeTrue()
        ->and($fn->owner)->toBe('app_migrator')
        ->and($fn->proconfig)->toContain('search_path=pg_catalog, pg_temp')
        ->and($fn->return_type)->toBe('uuid');
})->with(LOOKUP_FUNCTIONS);

it('sono eseguibili solo da app_runtime', function (string $name) {
    $signature = $name === 'app_user_tenants' ? 'public.app_user_tenants(uuid)' : 'public.app_tenant_by_slug(text)';
    $can = fn (string $role): bool => DB::selectOne(
        'select has_function_privilege(?, ?, \'EXECUTE\') as g', [$role, $signature]
    )->g;

    $publicAcl = DB::selectOne(
        "select coalesce(bool_or(a.grantee = 0), false) as public_exec
         from pg_proc p, aclexplode(coalesce(p.proacl, acldefault('f', p.proowner))) a
         where p.proname = ? and a.privilege_type = 'EXECUTE'", [$name]
    )->public_exec;

    expect($can('app_runtime'))->toBeTrue()
        ->and($can('app_admin_ops'))->toBeFalse()
        ->and($publicAcl)->toBeFalse();
})->with(LOOKUP_FUNCTIONS);

it('app_user_tenants restituisce solo gli ID dei tenant dell\'utente', function () {
    $t = twoTenantsWithUnits();
    $user = createUserRow();
    createMemberRow($t['a']['tenant'], $user);
    createMemberRow($t['b']['tenant'], createUserRow());

    $rows = DB::select('select * from app_user_tenants(?)', [$user]);

    expect($rows)->toHaveCount(1)
        ->and(array_keys((array) $rows[0]))->toBe(['app_user_tenants'])
        ->and($rows[0]->app_user_tenants)->toBe($t['a']['tenant'])
        ->and(DB::select('select * from app_user_tenants(?)', [uuid7()]))->toBe([]);
});

it('app_tenant_by_slug restituisce solo l\'ID del tenant, o null', function () {
    $tenant = createTenantRow('slug-di-prova');

    expect(DB::selectOne('select app_tenant_by_slug(?) as id', ['slug-di-prova'])->id)->toBe($tenant)
        ->and(DB::selectOne('select app_tenant_by_slug(?) as id', ['inesistente'])->id)->toBeNull();
});

it('chiamarle non apre l\'accesso alle righe: senza contesto app_runtime continua a non vedere nulla', function () {
    $tenant = createTenantRow('altro-slug');
    $user = createUserRow();
    createMemberRow($tenant, $user);

    DB::select('select * from app_user_tenants(?)', [$user]);
    DB::select('select app_tenant_by_slug(?)', ['altro-slug']);

    expect(DB::table('tenants')->count())->toBe(0)
        ->and(DB::table('tenant_members')->count())->toBe(0)
        ->and(DB::selectOne('select app_current_tenant() as t')->t)->toBeNull();
});

it('non accettano un argomento che non sia del tipo previsto', function () {
    DB::select('select * from app_user_tenants(?)', ["x' or 1=1 --"]);
})->throws(QueryException::class, 'invalid input syntax for type uuid');
