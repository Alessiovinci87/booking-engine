<?php

use App\Domain\Privacy\Enums\RetentionCategory;
use App\Domain\Tenancy\Database\TenantTable;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
 * Verifica di 6.11 (ADR-0016): categoria di conservazione H per i link di reimpostazione della password
 * usati o scaduti (`user_password_resets`), 30 giorni, cancellazione (09). La durata vive in
 * retention_policies e si cambia senza toccare il codice; la cancellazione la esegue il job di 7.2.
 * Non validata dal professionista finché `validated_by`/`validated_at` sono vuoti.
 */
return new class extends Migration
{
    private const PREVIOUS = ['A_ADMINISTRATIVE', 'B_GUEST_IDENTITY', 'C_PAYMENT_REFERENCES', 'D_UNCONCLUDED_ATTEMPTS', 'E_IMPORTED_EVENTS', 'F_SECURITY_AUDIT', 'G_COMMUNICATIONS'];

    public function up(): void
    {
        DB::statement('ALTER TABLE retention_policies DROP CONSTRAINT IF EXISTS retention_policies_category_check');
        TenantTable::enumCheck('retention_policies', 'category', RetentionCategory::class);

        DB::table('retention_policies')->insertOrIgnore([
            'id' => (string) Str::uuid7(),
            'category' => RetentionCategory::H_PASSWORD_RESET_LINKS->value,
            'retention_days' => 30,
            'action' => 'DELETE',
            'legal_basis_note' => 'Link di reimpostazione della password usati o scaduti: interesse legittimo alla sicurezza dell\'account; 30 giorni dalla scadenza o dall\'uso (ADR-0016).',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('retention_policies')->where('category', 'H_PASSWORD_RESET_LINKS')->delete();
        DB::statement('ALTER TABLE retention_policies DROP CONSTRAINT IF EXISTS retention_policies_category_check');
        DB::statement("ALTER TABLE retention_policies ADD CONSTRAINT retention_policies_category_check CHECK (category IN ('".implode("', '", self::PREVIOUS)."'))");
    }
};
