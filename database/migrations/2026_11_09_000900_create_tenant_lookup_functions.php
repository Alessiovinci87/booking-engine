<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * Ricerche che servono prima di conoscere il tenant (08: il tenant si ricava da membership o slug).
 *
 * Funzioni SECURITY DEFINER di proprietà di app_migrator, con search_path fissato, che restituiscono
 * solo gli ID necessari. Le richieste HTTP non usano mai app_admin_ops.
 *
 * Con FORCE ROW LEVEL SECURITY anche il proprietario è soggetto a RLS: le policy definer_lookup
 * consentono ad app_migrator (e quindi solo a queste funzioni, quando chiamate da app_runtime) di
 * leggere tenants e tenant_members. app_migrator possiede già le tabelle e potrebbe comunque
 * modificarne le policy: non ottiene privilegi nuovi. app_runtime resta soggetto a tenant_isolation.
 *
 * EXECUTE revocato a PUBLIC e concesso solo ad app_runtime.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('CREATE POLICY definer_lookup ON tenants FOR SELECT TO app_migrator USING (true)');
        DB::statement('CREATE POLICY definer_lookup ON tenant_members FOR SELECT TO app_migrator USING (true)');

        // Tenant dell'utente (login e risoluzione del tenant del pannello).
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION app_user_tenants(p_user_id uuid) RETURNS SETOF uuid
            LANGUAGE sql STABLE SECURITY DEFINER
            SET search_path = pg_catalog, pg_temp
            AS $$
              SELECT m.tenant_id FROM public.tenant_members m
              WHERE m.user_id = p_user_id
              ORDER BY m.created_at, m.tenant_id
            $$
            SQL);

        // Tenant dallo slug pubblico (pagina ospite e API pubblica).
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION app_tenant_by_slug(p_slug text) RETURNS uuid
            LANGUAGE sql STABLE SECURITY DEFINER
            SET search_path = pg_catalog, pg_temp
            AS $$
              SELECT t.id FROM public.tenants t WHERE t.slug = p_slug
            $$
            SQL);

        foreach (['app_user_tenants(uuid)', 'app_tenant_by_slug(text)'] as $function) {
            DB::statement("REVOKE ALL ON FUNCTION {$function} FROM PUBLIC");
            DB::statement("REVOKE ALL ON FUNCTION {$function} FROM app_admin_ops");
            DB::statement("GRANT EXECUTE ON FUNCTION {$function} TO app_runtime");
        }
    }

    public function down(): void
    {
        DB::statement('DROP FUNCTION IF EXISTS app_tenant_by_slug(text)');
        DB::statement('DROP FUNCTION IF EXISTS app_user_tenants(uuid)');
        DB::statement('DROP POLICY IF EXISTS definer_lookup ON tenant_members');
        DB::statement('DROP POLICY IF EXISTS definer_lookup ON tenants');
    }
};
