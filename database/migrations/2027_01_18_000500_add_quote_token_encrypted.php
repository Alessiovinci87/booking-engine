<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Link del preventivo come i feed iCal (decisione dopo la verifica di M5, ADR-0013 punti 24 e 36):
 * token casuale di 32 byte, cifrato a riposo in `public_token_encrypted` (serve per mostrare di nuovo
 * il link nel pannello) e cercato per hash in `public_token_hash`. Nessun token deriva più da APP_KEY.
 *
 * I preventivi già presenti ricevono un token nuovo: i loro vecchi link smettono di funzionare
 * (nessun dato di produzione prima del pilota).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE quotes ADD COLUMN public_token_encrypted text');

        // La policy definer_lookup consente ad app_migrator di leggere i preventivi di tutti i tenant;
        // l'aggiornamento avviene con il tenant della riga impostato come nelle transazioni normali.
        foreach (DB::select('SELECT id, tenant_id FROM quotes') as $row) {
            DB::transaction(function () use ($row): void {
                DB::select("SELECT set_config('app.tenant_id', ?, true)", [$row->tenant_id]);
                $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
                DB::update('UPDATE quotes SET public_token_encrypted = ?, public_token_hash = ? WHERE id = ?', [
                    Crypt::encryptString($token), hash('sha256', $token), $row->id,
                ]);
            });
        }

        DB::statement('ALTER TABLE quotes ALTER COLUMN public_token_encrypted SET NOT NULL');
    }

    public function down(): void
    {
        Schema::table('quotes', fn ($table) => $table->dropColumn('public_token_encrypted'));
    }
};
