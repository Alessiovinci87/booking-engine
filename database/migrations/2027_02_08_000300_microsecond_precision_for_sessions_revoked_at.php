<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * Verifica di 6.11 (ADR-0016): `users.sessions_revoked_at` al microsecondo. La colonna era
 * timestamptz(0), quindi arrotondata al secondo: una sessione aperta subito dopo la revoca, nello stesso
 * secondo, veniva chiusa. Con timestamptz(6) il confronto con l'inizio della sessione
 * (`panel.authenticated_at_us`) è esatto.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE users ALTER COLUMN sessions_revoked_at TYPE timestamptz(6)');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE users ALTER COLUMN sessions_revoked_at TYPE timestamptz(0)');
    }
};
