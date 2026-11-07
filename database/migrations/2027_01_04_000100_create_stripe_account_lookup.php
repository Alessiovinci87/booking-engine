<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * Webhook di Stripe (05 "Webhook" passo 3, 08): il tenant si ricava da event.account. Stesso schema
 * delle funzioni di 2026_11_09_000900 e 2026_12_07_000100: SECURITY DEFINER di app_migrator,
 * search_path fissato, solo l'ID del tenant in uscita, EXECUTE solo ad app_runtime.
 *
 * La policy definer_lookup consente la lettura di stripe_accounts ad app_migrator, cioè solo a questa
 * funzione quando la chiama app_runtime; app_runtime resta soggetto a tenant_isolation.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('CREATE POLICY definer_lookup ON stripe_accounts FOR SELECT TO app_migrator USING (true)');

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION app_tenant_by_stripe_account(p_account text) RETURNS uuid
            LANGUAGE sql STABLE SECURITY DEFINER
            SET search_path = pg_catalog, pg_temp
            AS $$
              SELECT s.tenant_id FROM public.stripe_accounts s WHERE s.stripe_account_id = p_account
            $$
            SQL);

        DB::statement('REVOKE ALL ON FUNCTION app_tenant_by_stripe_account(text) FROM PUBLIC');
        DB::statement('REVOKE ALL ON FUNCTION app_tenant_by_stripe_account(text) FROM app_admin_ops');
        DB::statement('GRANT EXECUTE ON FUNCTION app_tenant_by_stripe_account(text) TO app_runtime');
    }

    public function down(): void
    {
        DB::statement('DROP FUNCTION IF EXISTS app_tenant_by_stripe_account(text)');
        DB::statement('DROP POLICY IF EXISTS definer_lookup ON stripe_accounts');
    }
};
