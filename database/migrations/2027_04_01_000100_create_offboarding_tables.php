<?php

use App\Domain\Tenancy\Database\TenantTable;
use App\Domain\Tenancy\Enums\TenantExportStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * 7.1 — Uscita del cliente (06 "Ciclo di vita dei feed", 09 "Uscita del cliente", ADR-0018):
 *
 * - ics_feeds.removal_confirmed_at: conferma del gestore, per ogni feed, di averlo rimosso dall'OTA;
 * - tenants.data_purged_at: cancellazione dei dati secondo il DPA eseguita (poi CLOSED);
 * - tenant_exports: export completo asincrono (file privato, scaricabile per 30 giorni);
 * - FK dei due cicli (preventivi ↔ prenotazioni, strutture ↔ politiche di cancellazione) DEFERRABLE
 *   INITIALLY IMMEDIATE: comportamento invariato, ma la cancellazione del tenant può rinviarne il
 *   controllo a fine transazione senza dare UPDATE al ruolo app_retention;
 * - privilegi di app_retention: SELECT e DELETE su ogni tabella con tenant_id (salvo tenants) e sulle
 *   tabelle globali degli utenti. Nessun INSERT né UPDATE.
 */
return new class extends Migration
{
    private const DEFERRABLE = [
        'quotes' => 'quotes_active_reservation_id_fk',
        'reservations' => 'reservations_quote_id_fk',
        'properties' => 'properties_default_cancellation_policy_id_fk',
        'cancellation_policies' => 'cancellation_policies_property_id_fk',
    ];

    private const GLOBAL_RETENTION_TABLES = ['users', 'user_recovery_codes', 'user_password_resets'];

    public function up(): void
    {
        Schema::table('ics_feeds', function (Blueprint $table): void {
            $table->timestampTz('removal_confirmed_at')->nullable();
        });

        Schema::table('tenants', function (Blueprint $table): void {
            $table->timestampTz('data_purged_at')->nullable();
        });

        TenantTable::create('tenant_exports', function (Blueprint $table): void {
            $table->foreignUuid('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('status')->default(TenantExportStatus::PENDING->value);
            $table->text('file_path')->nullable();
            $table->bigInteger('size_bytes')->nullable();
            $table->integer('future_reservations')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->timestampTz('expires_at')->nullable();
            $table->index(['tenant_id', 'created_at']);
        });
        TenantTable::enumCheck('tenant_exports', 'status', TenantExportStatus::class);
        TenantTable::check('tenant_exports', 'ready_consistent', "status <> 'READY' OR (file_path IS NOT NULL AND expires_at IS NOT NULL)");

        foreach (self::DEFERRABLE as $table => $constraint) {
            DB::statement("ALTER TABLE {$table} ALTER CONSTRAINT {$constraint} DEFERRABLE INITIALLY IMMEDIATE");
        }

        $exists = DB::selectOne("SELECT 1 AS ok FROM pg_roles WHERE rolname = 'app_retention'");

        if ($exists === null) {
            throw new RuntimeException('Ruolo app_retention mancante: eseguire docker/postgres/init/02-roles.sh (ADR-0018).');
        }

        foreach ([...self::tenantScopedTables(), ...self::GLOBAL_RETENTION_TABLES] as $table) {
            DB::statement("GRANT SELECT, DELETE ON {$table} TO app_retention");
        }
    }

    public function down(): void
    {
        foreach ([...self::tenantScopedTables(), ...self::GLOBAL_RETENTION_TABLES] as $table) {
            DB::statement("REVOKE ALL ON {$table} FROM app_retention");
        }

        foreach (self::DEFERRABLE as $table => $constraint) {
            DB::statement("ALTER TABLE {$table} ALTER CONSTRAINT {$constraint} NOT DEFERRABLE");
        }

        Schema::dropIfExists('tenant_exports');

        Schema::table('tenants', function (Blueprint $table): void {
            $table->dropColumn('data_purged_at');
        });

        Schema::table('ics_feeds', function (Blueprint $table): void {
            $table->dropColumn('removal_confirmed_at');
        });
    }

    /** @return list<string> */
    private static function tenantScopedTables(): array
    {
        return array_values(array_map(
            static fn (object $row): string => (string) $row->table_name,
            DB::select(<<<'SQL'
                SELECT c.table_name FROM information_schema.columns c
                JOIN information_schema.tables t ON t.table_schema = c.table_schema AND t.table_name = c.table_name
                WHERE c.table_schema = 'public' AND c.column_name = 'tenant_id' AND t.table_type = 'BASE TABLE'
                ORDER BY c.table_name
                SQL),
        ));
    }
};
