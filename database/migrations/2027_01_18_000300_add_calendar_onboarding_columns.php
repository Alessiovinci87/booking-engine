<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Collegamento dei calendari (06 "Topologia richiesta" e "Rimozione"; ADR-0013):
 *
 * - units.calendar_checklist: conferme esplicite del gestore per i passi della checklist di
 *   collegamento (chiave del passo → istante e utente della conferma);
 * - external_calendars.url_changed_at: cambio di URL di un calendario importato; gli eventi visti
 *   l'ultima volta prima del cambio non si rimuovono in automatico.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('units', function (Blueprint $table): void {
            $table->jsonb('calendar_checklist')->default('{}');
        });

        Schema::table('external_calendars', function (Blueprint $table): void {
            $table->timestampTz('url_changed_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('external_calendars', fn (Blueprint $table) => $table->dropColumn('url_changed_at'));
        Schema::table('units', fn (Blueprint $table) => $table->dropColumn('calendar_checklist'));
    }
};
