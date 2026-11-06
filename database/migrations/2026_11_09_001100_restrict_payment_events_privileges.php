<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * payment_events è append-only (02, ADR-0009), come audit_log: app_runtime può solo inserire e
 * leggere (sotto RLS). UPDATE, DELETE e TRUNCATE revocati esplicitamente.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('REVOKE UPDATE, DELETE, TRUNCATE ON payment_events FROM app_runtime');
        DB::statement('REVOKE INSERT, UPDATE, DELETE, TRUNCATE ON payment_events FROM app_admin_ops');
    }

    public function down(): void
    {
        DB::statement('GRANT UPDATE, DELETE ON payment_events TO app_runtime');
    }
};
