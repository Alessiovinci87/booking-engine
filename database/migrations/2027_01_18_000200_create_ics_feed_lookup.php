<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * GET /ical/{token}.ics (06, 07): il percorso contiene solo il token del feed, quindi il tenant si
 * ricava dall'hash del token. Stesso schema delle funzioni di 2026_11_09_000900, 2026_12_07_000100 e
 * 2027_01_04_000100: SECURITY DEFINER di app_migrator, search_path fissato, solo l'ID del tenant in
 * uscita, EXECUTE solo ad app_runtime.
 *
 * La policy definer_lookup consente la lettura di ics_feeds ad app_migrator, cioè solo a questa
 * funzione quando la chiama app_runtime; app_runtime resta soggetto a tenant_isolation.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('CREATE POLICY definer_lookup ON ics_feeds FOR SELECT TO app_migrator USING (true)');

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION app_tenant_by_ics_feed(p_token_hash text) RETURNS uuid
            LANGUAGE sql STABLE SECURITY DEFINER
            SET search_path = pg_catalog, pg_temp
            AS $$
              SELECT f.tenant_id FROM public.ics_feeds f WHERE f.token_hash = p_token_hash
            $$
            SQL);

        DB::statement('REVOKE ALL ON FUNCTION app_tenant_by_ics_feed(text) FROM PUBLIC');
        DB::statement('REVOKE ALL ON FUNCTION app_tenant_by_ics_feed(text) FROM app_admin_ops');
        DB::statement('GRANT EXECUTE ON FUNCTION app_tenant_by_ics_feed(text) TO app_runtime');
    }

    public function down(): void
    {
        DB::statement('DROP FUNCTION IF EXISTS app_tenant_by_ics_feed(text)');
        DB::statement('DROP POLICY IF EXISTS definer_lookup ON ics_feeds');
    }
};
