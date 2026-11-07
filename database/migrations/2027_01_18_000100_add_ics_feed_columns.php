<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Feed iCal esportati (06 "Un'occupazione logica, un UID", "Ciclo di vita dei feed"; ADR-0013):
 *
 * - ics_sequence su occupazioni ed eventi importati: SEQUENCE dell'evento nel feed, cresce a ogni
 *   cambio di date; la lettura del feed non scrive nulla;
 * - frozen_at sui feed: da quando il feed è FROZEN (rotazione del token o offboarding); un feed
 *   congelato non mostra elementi nati dopo.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['inventory_occupancies', 'external_calendar_events'] as $name) {
            Schema::table($name, function (Blueprint $table): void {
                $table->integer('ics_sequence')->default(0);
            });
            DB::statement("ALTER TABLE {$name} ADD CONSTRAINT {$name}_ics_sequence_valid CHECK (ics_sequence >= 0)");
        }

        Schema::table('ics_feeds', function (Blueprint $table): void {
            $table->timestampTz('frozen_at')->nullable();
        });
        DB::statement("ALTER TABLE ics_feeds ADD CONSTRAINT ics_feeds_frozen_has_date CHECK (status <> 'FROZEN' OR frozen_at IS NOT NULL)");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE ics_feeds DROP CONSTRAINT IF EXISTS ics_feeds_frozen_has_date');
        Schema::table('ics_feeds', fn (Blueprint $table) => $table->dropColumn('frozen_at'));

        foreach (['inventory_occupancies', 'external_calendar_events'] as $name) {
            DB::statement("ALTER TABLE {$name} DROP CONSTRAINT IF EXISTS {$name}_ics_sequence_valid");
            Schema::table($name, fn (Blueprint $table) => $table->dropColumn('ics_sequence'));
        }
    }
};
