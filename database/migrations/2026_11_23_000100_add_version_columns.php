<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Concorrenza ottimistica sulle modifiche admin (07, "If-Match"): version cresce a ogni modifica e la
 * PATCH con una versione diversa risponde 412 VERSION_MISMATCH.
 */
return new class extends Migration
{
    private const TABLES = ['properties', 'units', 'rate_plans', 'season_rules'];

    public function up(): void
    {
        foreach (self::TABLES as $name) {
            Schema::table($name, function (Blueprint $table): void {
                $table->integer('version')->default(1);
            });
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $name) {
            Schema::table($name, function (Blueprint $table): void {
                $table->dropColumn('version');
            });
        }
    }
};
