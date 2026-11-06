<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * I12 nel database (02, 06; 11, attività 2.5): un'unità con calendari esterni attivi non può essere
 * INSTANT. "Attivo" = ogni stato diverso da DISABLED (ACTIVE, DEGRADED, UNHEALTHY: il calendario è
 * ancora collegato e può portare prenotazioni dall'OTA).
 *
 * Due trigger BEFORE, uno per lato:
 * - units: booking_mode = INSTANT rifiutato se esiste un calendario attivo dell'unità;
 * - external_calendars: un calendario attivo rifiutato se l'unità è INSTANT. Il trigger blocca la riga
 *   dell'unità (FOR SHARE), così due transazioni concorrenti non possono violare l'invariante.
 *
 * Errore con SQLSTATE BE012, che UnitService traduce in 409 EXTERNAL_CALENDARS_ACTIVE. Le funzioni
 * sono SECURITY INVOKER: girano con il ruolo e il tenant della transazione (RLS invariata).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION app_units_instant_guard() RETURNS trigger
            LANGUAGE plpgsql
            SET search_path = pg_catalog, pg_temp
            AS $$
            BEGIN
              IF NEW.booking_mode = 'INSTANT' AND EXISTS (
                SELECT 1 FROM public.external_calendars c
                WHERE c.tenant_id = NEW.tenant_id AND c.unit_id = NEW.id AND c.status <> 'DISABLED'
              ) THEN
                RAISE EXCEPTION 'I12: unità con calendari esterni attivi non può essere INSTANT'
                  USING ERRCODE = 'BE012';
              END IF;
              RETURN NEW;
            END
            $$
            SQL);

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION app_external_calendars_instant_guard() RETURNS trigger
            LANGUAGE plpgsql
            SET search_path = pg_catalog, pg_temp
            AS $$
            DECLARE
              v_mode text;
            BEGIN
              IF NEW.status <> 'DISABLED' THEN
                SELECT u.booking_mode INTO v_mode FROM public.units u
                WHERE u.tenant_id = NEW.tenant_id AND u.id = NEW.unit_id
                FOR SHARE;

                IF v_mode = 'INSTANT' THEN
                  RAISE EXCEPTION 'I12: calendario esterno attivo su unità INSTANT'
                    USING ERRCODE = 'BE012';
                END IF;
              END IF;
              RETURN NEW;
            END
            $$
            SQL);

        DB::statement('CREATE TRIGGER units_instant_guard BEFORE INSERT OR UPDATE OF booking_mode ON units '
            .'FOR EACH ROW EXECUTE FUNCTION app_units_instant_guard()');
        DB::statement('CREATE TRIGGER external_calendars_instant_guard BEFORE INSERT OR UPDATE OF status, unit_id ON external_calendars '
            .'FOR EACH ROW EXECUTE FUNCTION app_external_calendars_instant_guard()');

        foreach (['app_units_instant_guard()', 'app_external_calendars_instant_guard()'] as $function) {
            DB::statement("REVOKE ALL ON FUNCTION {$function} FROM PUBLIC");
        }
    }

    public function down(): void
    {
        DB::statement('DROP TRIGGER IF EXISTS external_calendars_instant_guard ON external_calendars');
        DB::statement('DROP TRIGGER IF EXISTS units_instant_guard ON units');
        DB::statement('DROP FUNCTION IF EXISTS app_external_calendars_instant_guard()');
        DB::statement('DROP FUNCTION IF EXISTS app_units_instant_guard()');
    }
};
