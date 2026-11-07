<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Calendari importati dimostrativi (seeder demo, decisione dopo la verifica di M5, ADR-0013 punto 44):
 * `demo = true` esclude il calendario dal polling (DispatchCalendarSyncJob), da "Sincronizza ora" e
 * dalla valutazione della salute. I suoi eventi restano occupazioni a tutti gli effetti (conflitti,
 * feed, disponibilità). Nessuna chiamata esterna: l'URL è su un dominio `.invalid`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('external_calendars', function (Blueprint $table): void {
            $table->boolean('demo')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('external_calendars', function (Blueprint $table): void {
            $table->dropColumn('demo');
        });
    }
};
