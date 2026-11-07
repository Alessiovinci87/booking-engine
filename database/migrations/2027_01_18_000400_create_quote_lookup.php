<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * Preventivi (03 sezione 4, 07 "/public/quotes/{token}"; 5.7):
 *
 * - quotes.currency: la valuta del prezzo congelato, copiata dal piano tariffario alla creazione, così
 *   l'accettazione non rilegge le tariffe (04 "Preventivi", I14);
 * - app_tenant_by_quote: il percorso pubblico contiene solo il token del preventivo, quindi il tenant si
 *   ricava dall'hash del token. Stesso schema di app_tenant_by_ics_feed (2027_01_18_000200).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE quotes ADD COLUMN currency char(3) NOT NULL DEFAULT 'EUR'");

        DB::statement('CREATE POLICY definer_lookup ON quotes FOR SELECT TO app_migrator USING (true)');

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION app_tenant_by_quote(p_token_hash text) RETURNS uuid
            LANGUAGE sql STABLE SECURITY DEFINER
            SET search_path = pg_catalog, pg_temp
            AS $$
              SELECT q.tenant_id FROM public.quotes q WHERE q.public_token_hash = p_token_hash
            $$
            SQL);

        DB::statement('REVOKE ALL ON FUNCTION app_tenant_by_quote(text) FROM PUBLIC');
        DB::statement('REVOKE ALL ON FUNCTION app_tenant_by_quote(text) FROM app_admin_ops');
        DB::statement('GRANT EXECUTE ON FUNCTION app_tenant_by_quote(text) TO app_runtime');
    }

    public function down(): void
    {
        DB::statement('DROP FUNCTION IF EXISTS app_tenant_by_quote(text)');
        DB::statement('DROP POLICY IF EXISTS definer_lookup ON quotes');
        DB::statement('ALTER TABLE quotes DROP COLUMN IF EXISTS currency');
    }
};
