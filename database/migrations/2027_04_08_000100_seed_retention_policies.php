<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
 * Job di conservazione (7.2, 09 "Conservazione", ADR-0018):
 *
 * - durate delle categorie A–G con le ipotesi di lavoro di 09 come valori provvisori, non validate
 *   (`validated_by`/`validated_at` vuoti): finché non lo sono il job gira in simulazione;
 * - app_retention legge e cancella le righe di piattaforma dell'audit log (tenant_id NULL) solo senza
 *   contesto tenant, per la categoria F una volta per esecuzione. Le politiche sono permissive e si
 *   sommano a tenant_isolation, che resta l'unica per gli altri ruoli.
 */
return new class extends Migration
{
    private const POLICIES = [
        ['A_ADMINISTRATIVE', 3650, 'DELETE', 'Dati amministrativi della prenotazione: obblighi contabili e fiscali della struttura; ipotesi 10 anni dal check-out (09). Valore provvisorio.'],
        ['B_GUEST_IDENTITY', 730, 'ANONYMIZE', 'Dati identificativi e di contatto dell\'ospite: esecuzione del contratto di soggiorno; ipotesi 24 mesi dal check-out (09). Valore provvisorio.'],
        ['C_PAYMENT_REFERENCES', 3650, 'DELETE', 'Riferimenti ai pagamenti: obblighi contabili; come la categoria A (09). Valore provvisorio.'],
        ['D_UNCONCLUDED_ATTEMPTS', 90, 'ANONYMIZE', 'Tentativi non conclusi e preventivi non accettati: interesse legittimo alla gestione delle richieste; ipotesi 90 giorni (09). Valore provvisorio.'],
        ['E_IMPORTED_EVENTS', 30, 'DELETE', 'Eventi iCal importati: esecuzione del servizio; ipotesi 30 giorni dopo il check-out (09). Valore provvisorio.'],
        ['F_SECURITY_AUDIT', 730, 'DELETE', 'Audit di sicurezza: interesse legittimo alla sicurezza; ipotesi 24 mesi (09). Valore provvisorio.'],
        ['G_COMMUNICATIONS', 30, 'DELETE', 'Comunicazioni (messaggi in uscita): esecuzione del servizio; ipotesi 30 giorni dopo l\'invio (09). Valore provvisorio.'],
    ];

    public function up(): void
    {
        foreach (self::POLICIES as [$category, $days, $action, $note]) {
            DB::table('retention_policies')->insertOrIgnore([
                'id' => (string) Str::uuid7(),
                'category' => $category,
                'retention_days' => $days,
                'action' => $action,
                'legal_basis_note' => $note,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        DB::statement('CREATE POLICY retention_platform_select ON audit_log FOR SELECT TO app_retention USING (tenant_id IS NULL AND app_current_tenant() IS NULL)');
        DB::statement('CREATE POLICY retention_platform_delete ON audit_log FOR DELETE TO app_retention USING (tenant_id IS NULL AND app_current_tenant() IS NULL)');
    }

    public function down(): void
    {
        DB::statement('DROP POLICY IF EXISTS retention_platform_delete ON audit_log');
        DB::statement('DROP POLICY IF EXISTS retention_platform_select ON audit_log');
        DB::table('retention_policies')->whereIn('category', array_column(self::POLICIES, 0))->whereNull('validated_at')->delete();
    }
};
