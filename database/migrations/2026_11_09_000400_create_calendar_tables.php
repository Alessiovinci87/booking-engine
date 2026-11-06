<?php

use App\Domain\Calendar\Enums\ChannelSource;
use App\Domain\Calendar\Enums\EchoKind;
use App\Domain\Calendar\Enums\ExternalCalendarStatus;
use App\Domain\Calendar\Enums\ExternalEventStatus;
use App\Domain\Calendar\Enums\IcsFeedStatus;
use App\Domain\Calendar\Enums\SyncRunOutcome;
use App\Domain\Tenancy\Database\TenantTable;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * ExternalCalendar, ExternalCalendarEvent, SyncRun, IcsFeed (02, 06, ADR-0003).
 */
return new class extends Migration
{
    public function up(): void
    {
        TenantTable::create('external_calendars', function (Blueprint $table): void {
            TenantTable::belongsTo($table, 'unit_id', 'units');
            $table->text('source');
            $table->text('url_encrypted');
            $table->text('etag')->nullable();
            $table->text('last_modified')->nullable();
            $table->timestampTz('last_attempt_at')->nullable();
            $table->timestampTz('last_success_at')->nullable();
            $table->integer('consecutive_failures')->default(0);
            $table->text('status')->default(ExternalCalendarStatus::ACTIVE->value);
            $table->timestampTz('next_poll_at')->nullable();
            $table->index(['tenant_id', 'status', 'next_poll_at'], 'external_calendars_poll_idx');
        });
        TenantTable::enumCheck('external_calendars', 'source', ChannelSource::class);
        TenantTable::enumCheck('external_calendars', 'status', ExternalCalendarStatus::class);
        TenantTable::check('external_calendars', 'failures_valid', 'consecutive_failures >= 0');

        TenantTable::create('external_calendar_events', function (Blueprint $table): void {
            TenantTable::belongsTo($table, 'external_calendar_id', 'external_calendars', index: false);
            $table->text('uid');
            $table->text('summary_redacted')->nullable();
            $table->text('content_hash');
            $table->timestampTz('first_seen_at');
            $table->timestampTz('last_seen_at');
            $table->timestampTz('missing_since')->nullable();
            $table->text('echo_kind')->default(EchoKind::NONE->value);
            $table->text('echo_of_ics_uid')->nullable();
            $table->text('status')->default(ExternalEventStatus::ACTIVE->value);
            $table->unique(['tenant_id', 'external_calendar_id', 'uid'], 'external_calendar_events_uid_uniq');
        });
        TenantTable::stayColumn('external_calendar_events');
        TenantTable::enumCheck('external_calendar_events', 'echo_kind', EchoKind::class);
        TenantTable::enumCheck('external_calendar_events', 'status', ExternalEventStatus::class);
        TenantTable::check(
            'external_calendar_events',
            'echo_consistent',
            "echo_of_ics_uid IS NULL OR echo_kind = 'CONFIRMED_ECHO'"
        );
        DB::statement(
            'CREATE INDEX external_calendar_events_stay_idx ON external_calendar_events '
            .'USING gist (tenant_id, external_calendar_id, stay)'
        );

        TenantTable::create('sync_runs', function (Blueprint $table): void {
            TenantTable::belongsTo($table, 'external_calendar_id', 'external_calendars', index: false);
            $table->timestampTz('started_at');
            $table->timestampTz('finished_at')->nullable();
            $table->text('outcome')->nullable();
            $table->integer('http_status')->nullable();
            $table->text('error_code')->nullable();
            $table->integer('events_seen')->default(0);
            $table->integer('events_added')->default(0);
            $table->integer('events_updated')->default(0);
            $table->integer('events_pending_removal')->default(0);
            $table->integer('events_removed')->default(0);
            $table->integer('duration_ms')->nullable();
            $table->index(['tenant_id', 'external_calendar_id', 'started_at'], 'sync_runs_calendar_started_idx');
        });
        DB::statement(
            'ALTER TABLE sync_runs ADD CONSTRAINT sync_runs_outcome_check CHECK (outcome IS NULL OR outcome IN ('
            .implode(', ', array_map(fn (SyncRunOutcome $o): string => "'{$o->value}'", SyncRunOutcome::cases()))
            .'))'
        );
        TenantTable::check(
            'sync_runs',
            'counters_valid',
            'events_seen >= 0 AND events_added >= 0 AND events_updated >= 0 AND events_pending_removal >= 0 AND events_removed >= 0'
        );

        TenantTable::create('ics_feeds', function (Blueprint $table): void {
            TenantTable::belongsTo($table, 'unit_id', 'units');
            $table->text('destination');
            TenantTable::belongsTo($table, 'paired_external_calendar_id', 'external_calendars', nullable: true);
            $table->text('token_encrypted');
            $table->text('token_hash')->unique('ics_feeds_token_hash_uniq');
            $table->text('status')->default(IcsFeedStatus::ACTIVE->value);
            $table->timestampTz('last_fetched_at')->nullable();
            $table->text('last_fetch_user_agent')->nullable();
            $table->timestampTz('rotated_at')->nullable();
        });
        TenantTable::enumCheck('ics_feeds', 'destination', ChannelSource::class);
        TenantTable::enumCheck('ics_feeds', 'status', IcsFeedStatus::class);
        // Un feed per destinazione tra quelli non RETIRED (02).
        DB::statement(
            "CREATE UNIQUE INDEX ics_feeds_one_per_destination ON ics_feeds (tenant_id, unit_id, destination) WHERE status <> 'RETIRED'"
        );
    }

    public function down(): void
    {
        foreach (['ics_feeds', 'sync_runs', 'external_calendar_events', 'external_calendars'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
