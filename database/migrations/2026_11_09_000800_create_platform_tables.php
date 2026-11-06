<?php

use App\Domain\Privacy\Enums\RetentionAction;
use App\Domain\Privacy\Enums\RetentionCategory;
use App\Domain\Tenancy\Database\TenantTable;
use App\Domain\Tenancy\Enums\AuditActorType;
use App\Domain\Tenancy\Enums\OutboxStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Tabelle trasversali (02): AuditLog, OutboxMessage, RetentionPolicy.
 */
return new class extends Migration
{
    public function up(): void
    {
        /*
         * audit_log: append-only (08). tenant_id è NULL solo per gli eventi di sicurezza della piattaforma
         * che non appartengono a nessun tenant (es. login fallito con email sconosciuta, 09 "Sicurezza del
         * servizio"). La policy li rende scrivibili da app_runtime solo fuori da un contesto tenant e mai
         * leggibili: li legge soltanto app_admin_ops.
         */
        Schema::create('audit_log', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->nullable();
            $table->foreign('tenant_id', 'audit_log_tenant_fk')->references('id')->on('tenants');
            $table->text('actor_type');
            $table->uuid('actor_id')->nullable();
            $table->text('action');
            $table->text('subject_type')->nullable();
            $table->uuid('subject_id')->nullable();
            $table->jsonb('changes')->default('{}');
            $table->text('ip')->nullable();
            $table->text('user_agent')->nullable();
            $table->timestampTz('occurred_at');
            $table->index(['tenant_id', 'occurred_at'], 'audit_log_tenant_occurred_idx');
            $table->index(['tenant_id', 'subject_type', 'subject_id'], 'audit_log_subject_idx');
        });
        TenantTable::enumCheck('audit_log', 'actor_type', AuditActorType::class);
        DB::statement('ALTER TABLE audit_log ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE audit_log FORCE ROW LEVEL SECURITY');
        DB::statement(
            'CREATE POLICY tenant_isolation ON audit_log '
            .'USING (tenant_id = app_current_tenant()) '
            .'WITH CHECK (tenant_id = app_current_tenant() OR (tenant_id IS NULL AND app_current_tenant() IS NULL))'
        );

        TenantTable::create('outbox_messages', function (Blueprint $table): void {
            $table->text('type');
            $table->jsonb('payload')->nullable();
            $table->text('dedupe_key');
            $table->text('status')->default(OutboxStatus::PENDING->value);
            $table->integer('attempts')->default(0);
            $table->timestampTz('available_at');
            $table->timestampTz('sent_at')->nullable();
            $table->timestampTz('failed_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestampTz('payload_purged_at')->nullable();
            $table->unique(['tenant_id', 'dedupe_key'], 'outbox_messages_dedupe_uniq');
        });
        TenantTable::enumCheck('outbox_messages', 'status', OutboxStatus::class);
        TenantTable::check('outbox_messages', 'attempts_valid', 'attempts >= 0');
        DB::statement(
            'CREATE INDEX outbox_messages_pending_idx ON outbox_messages (available_at) '
            ."WHERE status = 'PENDING'"
        );

        // Configurazione globale della piattaforma per categoria (09): nessuna durata nel codice.
        Schema::create('retention_policies', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->text('category')->unique('retention_policies_category_uniq');
            $table->integer('retention_days');
            $table->text('action');
            $table->text('legal_basis_note')->nullable();
            $table->text('validated_by')->nullable();
            $table->timestampTz('validated_at')->nullable();
            $table->timestampsTz();
        });
        TenantTable::enumCheck('retention_policies', 'category', RetentionCategory::class);
        TenantTable::enumCheck('retention_policies', 'action', RetentionAction::class);
        TenantTable::check('retention_policies', 'days_valid', 'retention_days >= 1');
        TenantTable::check(
            'retention_policies',
            'validation_consistent',
            '(validated_by IS NULL) = (validated_at IS NULL)'
        );
    }

    public function down(): void
    {
        foreach (['retention_policies', 'outbox_messages', 'audit_log'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
