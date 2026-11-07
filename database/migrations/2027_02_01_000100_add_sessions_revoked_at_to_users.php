<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * 6.10 (user:reset-mfa): le sessioni del pannello stanno in Redis e non si possono elencare per utente.
 * Ogni sessione iniziata prima di `sessions_revoked_at` viene chiusa alla richiesta successiva
 * (EnsurePanelSessionIsFresh). Tabella globale senza tenant_id, come users.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->timestampTz('sessions_revoked_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('sessions_revoked_at');
        });
    }
};
