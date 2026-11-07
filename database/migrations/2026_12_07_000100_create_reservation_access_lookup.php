<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * GET /public/reservations/{code}?token= (07): il percorso non contiene lo slug, quindi il tenant si
 * ricava da codice e token. Stesso schema delle funzioni di 2026_11_09_000900: SECURITY DEFINER di
 * app_migrator, search_path fissato, solo l'ID del tenant in uscita, EXECUTE solo ad app_runtime.
 *
 * Restituisce il tenant solo se codice e hash del token corrispondono alla stessa prenotazione: senza
 * il token il codice non rivela nulla (nemmeno l'esistenza della prenotazione). La policy
 * definer_lookup consente la lettura di reservations ad app_migrator, cioè solo a questa funzione
 * quando la chiama app_runtime; app_runtime resta soggetto a tenant_isolation.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('CREATE POLICY definer_lookup ON reservations FOR SELECT TO app_migrator USING (true)');

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION app_tenant_by_reservation_access(p_code text, p_token_hash text) RETURNS uuid
            LANGUAGE sql STABLE SECURITY DEFINER
            SET search_path = pg_catalog, pg_temp
            AS $$
              SELECT r.tenant_id FROM public.reservations r
              WHERE r.code = p_code AND r.public_access_token_hash = p_token_hash
            $$
            SQL);

        DB::statement('REVOKE ALL ON FUNCTION app_tenant_by_reservation_access(text, text) FROM PUBLIC');
        DB::statement('REVOKE ALL ON FUNCTION app_tenant_by_reservation_access(text, text) FROM app_admin_ops');
        DB::statement('GRANT EXECUTE ON FUNCTION app_tenant_by_reservation_access(text, text) TO app_runtime');
    }

    public function down(): void
    {
        DB::statement('DROP FUNCTION IF EXISTS app_tenant_by_reservation_access(text, text)');
        DB::statement('DROP POLICY IF EXISTS definer_lookup ON reservations');
    }
};
