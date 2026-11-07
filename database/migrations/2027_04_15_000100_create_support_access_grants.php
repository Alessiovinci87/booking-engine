<?php

use App\Domain\Tenancy\Database\TenantTable;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * 7.5 — Accesso di supporto in sola lettura (08 "Accesso di supporto", ADR-0018):
 *
 * - users.is_platform_operator: operatore della piattaforma (comando support:operator). Un operatore non
 *   ha membership: il tenant del pannello viene solo da un'autorizzazione attiva;
 * - support_access_grants: autorizzazione a tempo (massimo 2 ore) con motivazione, creata dal comando
 *   support:open, revocabile dal gestore o dalla piattaforma; prima e ultima lettura;
 * - app_support_grant_for: autorizzazione attiva dell'operatore tramite SECURITY DEFINER, come
 *   app_user_tenants: il tenant non è ancora noto quando la richiesta arriva. L'istante arriva
 *   dall'applicazione (stesso orologio dei controlli in PHP, UTC).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->boolean('is_platform_operator')->default(false);
        });

        TenantTable::create('support_access_grants', function (Blueprint $table): void {
            $table->foreignUuid('operator_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('reason');
            $table->timestampTz('expires_at');
            $table->timestampTz('revoked_at')->nullable();
            $table->foreignUuid('revoked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('first_used_at')->nullable();
            $table->timestampTz('last_used_at')->nullable();
            $table->index(['tenant_id', 'created_at']);
            $table->index(['operator_user_id', 'expires_at']);
        });
        TenantTable::check('support_access_grants', 'reason_length', 'char_length(reason) BETWEEN 10 AND 200');
        TenantTable::check('support_access_grants', 'max_two_hours', "expires_at > created_at AND expires_at <= created_at + interval '2 hours'");

        DB::statement('CREATE POLICY definer_lookup ON support_access_grants FOR SELECT TO app_migrator USING (true)');

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION app_support_grant_for(p_user_id uuid, p_now timestamptz) RETURNS TABLE (grant_id uuid, tenant_id uuid)
            LANGUAGE sql STABLE SECURITY DEFINER
            SET search_path = pg_catalog, pg_temp
            AS $$
              SELECT g.id, g.tenant_id FROM public.support_access_grants g
              WHERE g.operator_user_id = p_user_id AND g.revoked_at IS NULL AND g.expires_at > p_now
              ORDER BY g.expires_at DESC, g.id
              LIMIT 1
            $$
            SQL);

        DB::statement('REVOKE ALL ON FUNCTION app_support_grant_for(uuid, timestamptz) FROM PUBLIC');
        DB::statement('REVOKE ALL ON FUNCTION app_support_grant_for(uuid, timestamptz) FROM app_admin_ops');
        DB::statement('GRANT EXECUTE ON FUNCTION app_support_grant_for(uuid, timestamptz) TO app_runtime');

        // Conservazione e cancellazione secondo il DPA (7.1, 7.2): SELECT e DELETE come per le altre tabelle.
        DB::statement('GRANT SELECT, DELETE ON support_access_grants TO app_retention');
    }

    public function down(): void
    {
        DB::statement('DROP FUNCTION IF EXISTS app_support_grant_for(uuid, timestamptz)');
        Schema::dropIfExists('support_access_grants');

        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('is_platform_operator');
        });
    }
};
