<?php

use App\Domain\Tenancy\Database\TenantTable;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Finestra di approvazione per struttura (03 "Tempi": 12 h predefinite, 1–48 h per struttura, sempre
 * ≤ capture_before − 6 h). Decisione dopo la verifica di M3, completata in M4 (ADR-0012 punto 22).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('properties', function (Blueprint $table): void {
            $table->smallInteger('approval_window_hours')->default(12);
        });

        TenantTable::check('properties', 'approval_window_range', 'approval_window_hours BETWEEN 1 AND 48');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE properties DROP CONSTRAINT IF EXISTS properties_approval_window_range');

        Schema::table('properties', function (Blueprint $table): void {
            $table->dropColumn('approval_window_hours');
        });
    }
};
