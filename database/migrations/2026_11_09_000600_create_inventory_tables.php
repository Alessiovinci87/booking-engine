<?php

use App\Domain\Inventory\Enums\ConflictResolution;
use App\Domain\Inventory\Enums\ConflictStatus;
use App\Domain\Inventory\Enums\OccupancyKind;
use App\Domain\Inventory\Enums\OccupancyPhase;
use App\Domain\Inventory\Enums\OccupancyStatus;
use App\Domain\Inventory\Enums\OccupancyTempReason;
use App\Domain\Tenancy\Database\TenantTable;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * InventoryOccupancy, unica fonte delle occupazioni interne, e InventoryConflict (02 "Vincoli di
 * inventario", ADR-0002). Le righe di inventory_occupancies si scrivono solo tramite InventoryService.
 */
return new class extends Migration
{
    public function up(): void
    {
        TenantTable::create('inventory_occupancies', function (Blueprint $table): void {
            TenantTable::belongsTo($table, 'unit_id', 'units');
            $table->text('kind');
            $table->text('phase');
            $table->text('temp_reason')->nullable();
            $table->timestampTz('expires_at')->nullable();
            TenantTable::belongsTo($table, 'reservation_id', 'reservations', nullable: true);
            $table->text('status')->default(OccupancyStatus::ACTIVE->value);
            $table->timestampTz('released_at')->nullable();
            $table->text('release_reason')->nullable();
            $table->text('ics_uid')->unique('occupancies_ics_uid_uniq');
            $table->text('note')->nullable();
        });
        TenantTable::stayColumn('inventory_occupancies');
        TenantTable::enumCheck('inventory_occupancies', 'kind', OccupancyKind::class);
        TenantTable::enumCheck('inventory_occupancies', 'phase', OccupancyPhase::class);
        TenantTable::enumCheck('inventory_occupancies', 'status', OccupancyStatus::class);
        DB::statement(
            'ALTER TABLE inventory_occupancies ADD CONSTRAINT inventory_occupancies_temp_reason_check '
            ."CHECK (temp_reason IS NULL OR temp_reason IN ('"
            .implode("', '", array_map(fn (OccupancyTempReason $r): string => $r->value, OccupancyTempReason::cases()))
            ."'))"
        );

        // Vincoli di 02, con i nomi della specifica.
        DB::statement(
            'ALTER TABLE inventory_occupancies ADD CONSTRAINT occupancies_no_overlap '
            ."EXCLUDE USING gist (unit_id WITH =, stay WITH &&) WHERE (status = 'ACTIVE')"
        );
        DB::statement(
            'ALTER TABLE inventory_occupancies ADD CONSTRAINT occupancies_stay_valid '
            .'CHECK (NOT isempty(stay) AND lower_inc(stay) AND NOT upper_inc(stay))'
        );
        DB::statement(
            'ALTER TABLE inventory_occupancies ADD CONSTRAINT occupancies_phase_consistent '
            ."CHECK ((phase = 'FIRM' AND temp_reason IS NULL) OR (phase = 'TEMPORARY' AND temp_reason IS NOT NULL))"
        );
        DB::statement(
            'CREATE UNIQUE INDEX occupancies_one_per_reservation '
            .'ON inventory_occupancies (reservation_id) WHERE reservation_id IS NOT NULL'
        );
        // reservation_id obbligatorio se kind = RESERVATION; note solo per MANUAL_BLOCK (02).
        DB::statement(
            'ALTER TABLE inventory_occupancies ADD CONSTRAINT occupancies_kind_consistent '
            ."CHECK ((kind = 'RESERVATION') = (reservation_id IS NOT NULL) AND (note IS NULL OR kind = 'MANUAL_BLOCK'))"
        );
        DB::statement(
            'ALTER TABLE inventory_occupancies ADD CONSTRAINT occupancies_release_consistent '
            ."CHECK ((status = 'RELEASED') = (released_at IS NOT NULL))"
        );
        DB::statement(
            'CREATE INDEX inventory_occupancies_expiring_idx ON inventory_occupancies (tenant_id, expires_at) '
            ."WHERE status = 'ACTIVE' AND phase = 'TEMPORARY'"
        );

        TenantTable::create('inventory_conflicts', function (Blueprint $table): void {
            TenantTable::belongsTo($table, 'unit_id', 'units');
            TenantTable::belongsTo($table, 'occupancy_id', 'inventory_occupancies', nullable: true);
            TenantTable::belongsTo($table, 'external_event_id', 'external_calendar_events', nullable: true);
            TenantTable::belongsTo($table, 'other_external_event_id', 'external_calendar_events', nullable: true);
            $table->boolean('suspected_echo')->default(false);
            $table->text('status');
            $table->timestampTz('detected_at');
            $table->timestampTz('resolved_at')->nullable();
            $table->foreignUuid('resolved_by')->nullable()->constrained('users');
            $table->text('resolution')->nullable();
            $table->text('resolution_notes')->nullable();
            $table->index(['tenant_id', 'status'], 'inventory_conflicts_status_idx');
        });
        TenantTable::stayColumn('inventory_conflicts');
        TenantTable::enumCheck('inventory_conflicts', 'status', ConflictStatus::class);
        DB::statement(
            'ALTER TABLE inventory_conflicts ADD CONSTRAINT inventory_conflicts_resolution_check '
            ."CHECK (resolution IS NULL OR resolution IN ('"
            .implode("', '", array_map(fn (ConflictResolution $r): string => $r->value, ConflictResolution::cases()))
            ."'))"
        );
        // RESOLVED richiede resolution; IGNORED richiede note (03, sezione 5).
        TenantTable::check('inventory_conflicts', 'resolved_has_resolution', "status <> 'RESOLVED' OR resolution IS NOT NULL");
        TenantTable::check('inventory_conflicts', 'ignored_has_notes', "status <> 'IGNORED' OR resolution_notes IS NOT NULL");
        TenantTable::check(
            'inventory_conflicts',
            'has_source',
            'occupancy_id IS NOT NULL OR external_event_id IS NOT NULL OR other_external_event_id IS NOT NULL'
        );
        DB::statement('CREATE INDEX inventory_conflicts_stay_idx ON inventory_conflicts USING gist (tenant_id, unit_id, stay)');
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_conflicts');
        Schema::dropIfExists('inventory_occupancies');
    }
};
