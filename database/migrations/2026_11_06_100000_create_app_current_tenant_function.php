<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * Funzione usata da tutte le policy RLS (08): restituisce il tenant impostato con SET LOCAL da
 * TenantContext, oppure NULL se non impostato (nessuna riga visibile né scrivibile: fail-closed).
 * Un valore non UUID solleva errore e fa fallire la transazione.
 *
 * CREATE OR REPLACE: migrate:fresh elimina le tabelle ma non le funzioni.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION app_current_tenant() RETURNS uuid
            LANGUAGE sql STABLE AS $$
              SELECT NULLIF(current_setting('app.tenant_id', true), '')::uuid
            $$
            SQL);
    }

    public function down(): void
    {
        DB::statement('DROP FUNCTION IF EXISTS app_current_tenant()');
    }
};
