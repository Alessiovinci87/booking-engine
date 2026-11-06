<?php

use App\Domain\Tenancy\Database\TenantTable;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * RatePlan e SeasonRule (02 rimanda a 04): prezzo base per notte, stagioni per intervallo di date con
 * priorità e minimo notti facoltativo. Un solo piano attivo per unità.
 */
return new class extends Migration
{
    public function up(): void
    {
        TenantTable::create('rate_plans', function (Blueprint $table): void {
            TenantTable::belongsTo($table, 'unit_id', 'units');
            $table->text('name');
            $table->bigInteger('base_nightly_cents');
            $table->char('currency', 3)->default('EUR');
            $table->boolean('active')->default(true);
        });
        TenantTable::check('rate_plans', 'base_valid', 'base_nightly_cents > 0');
        TenantTable::check('rate_plans', 'currency_valid', "currency ~ '^[A-Z]{3}$'");
        DB::statement('CREATE UNIQUE INDEX rate_plans_one_active_per_unit ON rate_plans (tenant_id, unit_id) WHERE active');

        TenantTable::create('season_rules', function (Blueprint $table): void {
            TenantTable::belongsTo($table, 'rate_plan_id', 'rate_plans');
            $table->text('name');
            $table->bigInteger('nightly_cents');
            $table->integer('priority')->default(0);
            $table->integer('min_nights')->nullable();
        });
        // date_range: intervallo di notti [inizio, fine) come i soggiorni.
        TenantTable::stayColumn('season_rules', 'date_range');
        TenantTable::check('season_rules', 'nightly_valid', 'nightly_cents > 0');
        TenantTable::check('season_rules', 'min_nights_valid', 'min_nights IS NULL OR min_nights >= 1');
        DB::statement('CREATE INDEX season_rules_range_idx ON season_rules USING gist (tenant_id, rate_plan_id, date_range)');
    }

    public function down(): void
    {
        Schema::dropIfExists('season_rules');
        Schema::dropIfExists('rate_plans');
    }
};
