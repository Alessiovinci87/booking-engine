<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * 6.11 "Password dimenticata" (ADR-0016): link monouso valido 60 minuti. Tabella globale senza
 * tenant_id, come user_recovery_codes: l'utente non è ancora autenticato e il tenant non è noto.
 *
 * - token_hash: SHA-256 del token, unico; la verifica avviene solo sull'hash;
 * - token_encrypted: il token cifrato con la chiave dell'applicazione, perché l'handler dell'outbox
 *   possa ricostruire il link senza metterlo nel payload; azzerato all'uso, alla scadenza o quando
 *   un nuovo link sostituisce il precedente.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_password_resets', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
            $table->text('token_hash')->unique();
            $table->text('token_encrypted')->nullable();
            $table->timestampTz('expires_at');
            $table->timestampTz('used_at')->nullable();
            $table->timestampsTz();
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_password_resets');
    }
};
