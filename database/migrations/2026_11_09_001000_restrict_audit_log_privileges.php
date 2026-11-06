<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * audit_log è append-only (02, 08): app_runtime può solo inserire e leggere (sotto RLS).
 * UPDATE, DELETE e TRUNCATE revocati esplicitamente, anche se concessi dai privilegi predefiniti.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('REVOKE UPDATE, DELETE, TRUNCATE ON audit_log FROM app_runtime');
        DB::statement('REVOKE INSERT, UPDATE, DELETE, TRUNCATE ON audit_log FROM app_admin_ops');
    }

    public function down(): void
    {
        DB::statement('GRANT UPDATE, DELETE ON audit_log TO app_runtime');
    }
};
