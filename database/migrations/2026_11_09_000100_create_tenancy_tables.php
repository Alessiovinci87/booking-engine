<?php

use App\Domain\Tenancy\Database\TenantTable;
use App\Domain\Tenancy\Enums\Locale;
use App\Domain\Tenancy\Enums\MemberRole;
use App\Domain\Tenancy\Enums\TenantPlan;
use App\Domain\Tenancy\Enums\TenantStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Tenant, User, TenantMember (02). Tabelle globali senza tenant_id: users e user_recovery_codes.
 * tenants non ha tenant_id: RLS la limita a id = app_current_tenant() (02, 08).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenants', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->text('name');
            $table->text('slug')->unique('tenants_slug_uniq');
            $table->text('status')->default(TenantStatus::ACTIVE->value);
            $table->text('plan')->default(TenantPlan::STARTER->value);
            $table->text('default_locale')->default(Locale::IT->value);
            $table->text('timezone')->default('Europe/Rome');
            $table->timestampTz('dpa_signed_at')->nullable();
            $table->text('dpa_version')->nullable();
            $table->timestampTz('offboarding_started_at')->nullable();
            $table->timestampTz('channels_disconnected_confirmed_at')->nullable();
            $table->timestampTz('feeds_disabled_at')->nullable();
            $table->timestampsTz();
        });
        DB::statement("ALTER TABLE tenants ADD COLUMN embed_allowed_origins text[] NOT NULL DEFAULT '{}'");
        TenantTable::enumCheck('tenants', 'status', TenantStatus::class);
        TenantTable::enumCheck('tenants', 'plan', TenantPlan::class);
        TenantTable::enumCheck('tenants', 'default_locale', Locale::class);
        TenantTable::check('tenants', 'slug_format', "slug ~ '^[a-z0-9]([a-z0-9-]{1,61}[a-z0-9])?$'");
        DB::statement('ALTER TABLE tenants ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE tenants FORCE ROW LEVEL SECURITY');
        DB::statement(
            'CREATE POLICY tenant_isolation ON tenants '
            .'USING (id = app_current_tenant()) WITH CHECK (id = app_current_tenant())'
        );

        Schema::create('users', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->text('email');
            $table->text('password_hash');
            $table->text('mfa_secret_encrypted')->nullable();
            $table->timestampTz('mfa_enabled_at')->nullable();
            $table->text('locale')->default(Locale::IT->value);
            $table->timestampsTz();
        });
        // Email univoca senza distinzione tra maiuscole e minuscole (02).
        DB::statement('CREATE UNIQUE INDEX users_email_lower_uniq ON users (lower(email))');
        TenantTable::enumCheck('users', 'locale', Locale::class);
        TenantTable::check('users', 'mfa_consistent', 'mfa_enabled_at IS NULL OR mfa_secret_encrypted IS NOT NULL');

        // Codici di recupero MFA monouso, salvati solo come hash (08).
        Schema::create('user_recovery_codes', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
            $table->text('code_hash');
            $table->timestampTz('used_at')->nullable();
            $table->timestampsTz();
            $table->unique(['user_id', 'code_hash']);
        });

        TenantTable::create('tenant_members', function (Blueprint $table): void {
            $table->foreignUuid('user_id')->constrained('users');
            $table->text('role');
            $table->unique(['tenant_id', 'user_id'], 'tenant_members_tenant_user_uniq');
            $table->index('user_id');
        });
        TenantTable::enumCheck('tenant_members', 'role', MemberRole::class);
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_members');
        Schema::dropIfExists('user_recovery_codes');
        Schema::dropIfExists('users');
        Schema::dropIfExists('tenants');
    }
};
