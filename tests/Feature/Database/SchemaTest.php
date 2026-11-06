<?php

use App\Domain\Calendar\Enums\ChannelSource;
use App\Domain\Calendar\Enums\EchoKind;
use App\Domain\Calendar\Enums\ExternalCalendarStatus;
use App\Domain\Calendar\Enums\ExternalEventStatus;
use App\Domain\Calendar\Enums\IcsFeedStatus;
use App\Domain\Inventory\Enums\BookingMode;
use App\Domain\Inventory\Enums\ConflictStatus;
use App\Domain\Inventory\Enums\OccupancyKind;
use App\Domain\Inventory\Enums\OccupancyPhase;
use App\Domain\Inventory\Enums\OccupancyStatus;
use App\Domain\Inventory\Enums\PaymentMode;
use App\Domain\Inventory\Enums\PropertyStatus;
use App\Domain\Inventory\Enums\SyncHealth;
use App\Domain\Inventory\Enums\UnitStatus;
use App\Domain\Payments\Enums\CaptureMethod;
use App\Domain\Payments\Enums\PaymentPurpose;
use App\Domain\Payments\Enums\PaymentStatus;
use App\Domain\Payments\Enums\RefundMethod;
use App\Domain\Payments\Enums\RefundStatus;
use App\Domain\Payments\Enums\WebhookEventStatus;
use App\Domain\Pricing\Enums\PriceLineKind;
use App\Domain\Privacy\Enums\RetentionAction;
use App\Domain\Privacy\Enums\RetentionCategory;
use App\Domain\Quotes\Enums\QuoteStatus;
use App\Domain\Reservations\Enums\CancellationPolicyCode;
use App\Domain\Reservations\Enums\ReservationChannel;
use App\Domain\Reservations\Enums\ReservationStatus;
use App\Domain\Tenancy\Enums\AuditActorType;
use App\Domain\Tenancy\Enums\MemberRole;
use App\Domain\Tenancy\Enums\OutboxStatus;
use App\Domain\Tenancy\Enums\TenantPlan;
use App\Domain\Tenancy\Enums\TenantStatus;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/*
 * Attività 1.1: tutte le entità di 02, con le convenzioni di CLAUDE.md (UUID, timestamptz, centesimi
 * BIGINT, soggiorni DATERANGE) e i vincoli di integrità tra tenant (ADR-0005).
 */

const TENANT_SCOPED_TABLES = [
    'tenant_members', 'stripe_accounts', 'properties', 'property_translations', 'cancellation_policies',
    'units', 'unit_translations', 'unit_photos', 'rate_plans', 'season_rules', 'guests',
    'inventory_occupancies', 'inventory_conflicts', 'reservations', 'reservation_price_lines',
    'reservation_changes', 'quotes', 'payments', 'payment_events', 'refunds', 'ics_feeds',
    'external_calendars', 'external_calendar_events', 'sync_runs', 'outbox_messages',
];

const GLOBAL_TABLES = ['tenants', 'users', 'user_recovery_codes', 'webhook_events', 'retention_policies', 'audit_log'];

/** @return list<object> */
function publicColumns(): array
{
    return DB::select(<<<'SQL'
        SELECT c.table_name, c.column_name, c.data_type, c.udt_name
        FROM information_schema.columns c
        JOIN information_schema.tables t ON t.table_schema = c.table_schema AND t.table_name = c.table_name
        WHERE c.table_schema = 'public' AND t.table_type = 'BASE TABLE'
        SQL);
}

it('ha tutte le tabelle delle entità di 02', function () {
    $tables = collect(DB::select("SELECT tablename FROM pg_tables WHERE schemaname = 'public'"))->pluck('tablename');

    expect($tables->all())->toContain(...TENANT_SCOPED_TABLES, ...GLOBAL_TABLES);
});

it('ha tenant_id NOT NULL su ogni tabella tenant-scoped e nessun tenant_id sulle tabelle globali', function () {
    $tenantColumns = collect(DB::select(
        "SELECT table_name, is_nullable FROM information_schema.columns WHERE table_schema = 'public' AND column_name = 'tenant_id'"
    ))->keyBy('table_name');

    foreach (TENANT_SCOPED_TABLES as $table) {
        expect($tenantColumns->get($table)?->is_nullable)->toBe('NO', "tenant_id NOT NULL mancante su {$table}");
    }

    foreach (['tenants', 'users', 'user_recovery_codes', 'webhook_events', 'retention_policies'] as $table) {
        expect($tenantColumns->has($table))->toBeFalse("{$table} è globale e non deve avere tenant_id");
    }
});

it('ha UNIQUE (tenant_id, id) su ogni tabella tenant-scoped', function () {
    $withUnique = collect(DB::select(<<<'SQL'
        SELECT cls.relname AS table_name
        FROM pg_constraint con
        JOIN pg_class cls ON cls.oid = con.conrelid
        JOIN pg_namespace n ON n.oid = cls.relnamespace
        WHERE n.nspname = 'public' AND con.contype = 'u'
          AND (SELECT array_agg(a.attname ORDER BY k.ord)
               FROM unnest(con.conkey) WITH ORDINALITY AS k(attnum, ord)
               JOIN pg_attribute a ON a.attrelid = con.conrelid AND a.attnum = k.attnum) = ARRAY['tenant_id', 'id']::name[]
        SQL))->pluck('table_name')->all();

    expect(array_values(array_diff(TENANT_SCOPED_TABLES, $withUnique)))->toBe([]);
});

it('usa UUID per tutte le chiavi primarie e i riferimenti', function () {
    $wrong = collect(publicColumns())
        ->filter(fn ($c) => ($c->column_name === 'id' || str_ends_with($c->column_name, '_id'))
            && ! in_array($c->table_name, ['cache', 'cache_locks', 'jobs', 'job_batches', 'failed_jobs', 'migrations'], true))
        // Identificativi esterni testuali (Stripe, eventi webhook).
        ->reject(fn ($c) => in_array($c->column_name, ['stripe_account_id', 'checkout_session_id', 'payment_intent_id', 'stripe_refund_id', 'stripe_object_id', 'event_id', 'account_id'], true))
        ->filter(fn ($c) => $c->udt_name !== 'uuid')
        ->map(fn ($c) => "{$c->table_name}.{$c->column_name} ({$c->udt_name})")
        ->values()->all();

    expect($wrong)->toBe([]);
});

it('usa solo timestamptz, mai timestamp senza fuso', function () {
    $wrong = collect(publicColumns())
        ->filter(fn ($c) => $c->data_type === 'timestamp without time zone')
        // Tabelle di sistema di Laravel (code), non entità di 02.
        ->reject(fn ($c) => in_array($c->table_name, ['failed_jobs', 'job_batches', 'jobs'], true))
        ->map(fn ($c) => "{$c->table_name}.{$c->column_name}")
        ->values()->all();

    expect($wrong)->toBe([]);
});

it('salva gli importi in centesimi come BIGINT', function () {
    $columns = collect(publicColumns())->filter(fn ($c) => str_ends_with($c->column_name, '_cents'));

    expect($columns)->not->toBeEmpty()
        ->and($columns->reject(fn ($c) => $c->udt_name === 'int8')->map(fn ($c) => "{$c->table_name}.{$c->column_name}")->values()->all())
        ->toBe([]);
});

it('salva i soggiorni come DATERANGE', function () {
    $stays = collect(publicColumns())
        ->filter(fn ($c) => in_array($c->column_name, ['stay', 'previous_stay', 'new_stay', 'date_range'], true));

    expect($stays->map(fn ($c) => "{$c->table_name}.{$c->column_name}")->sort()->values()->all())->toBe([
        'external_calendar_events.stay', 'inventory_conflicts.stay', 'inventory_occupancies.stay',
        'quotes.stay', 'reservation_changes.new_stay', 'reservation_changes.previous_stay',
        'reservations.stay', 'season_rules.date_range',
    ])->and($stays->every(fn ($c) => $c->udt_name === 'daterange'))->toBeTrue();
});

it('ha un indice con tenant_id in testa per ogni FK composita', function () {
    $missing = DB::select(<<<'SQL'
        SELECT con.conname
        FROM pg_constraint con
        JOIN pg_namespace n ON n.oid = (SELECT relnamespace FROM pg_class WHERE oid = con.conrelid)
        WHERE con.contype = 'f' AND n.nspname = 'public' AND array_length(con.conkey, 1) = 2
          AND NOT EXISTS (
            SELECT 1 FROM pg_index i
            WHERE i.indrelid = con.conrelid
              AND (i.indkey::int2[])[0:1] = con.conkey
          )
        SQL);

    expect(array_map(fn ($r) => $r->conname, $missing))->toBe([]);
});

it('genera i vincoli CHECK degli stati dai valori degli enum PHP', function (string $table, string $column, string $enum) {
    $definition = DB::selectOne(
        'SELECT pg_get_constraintdef(oid) AS def FROM pg_constraint WHERE conname = ?',
        ["{$table}_{$column}_check"],
    )?->def;

    expect($definition)->not->toBeNull("CHECK mancante su {$table}.{$column}");

    foreach ($enum::cases() as $case) {
        expect($definition)->toContain("'{$case->value}'");
    }
})->with([
    ['tenants', 'status', TenantStatus::class],
    ['tenants', 'plan', TenantPlan::class],
    ['tenant_members', 'role', MemberRole::class],
    ['properties', 'status', PropertyStatus::class],
    ['units', 'status', UnitStatus::class],
    ['units', 'booking_mode', BookingMode::class],
    ['units', 'payment_mode', PaymentMode::class],
    ['units', 'sync_health', SyncHealth::class],
    ['cancellation_policies', 'code', CancellationPolicyCode::class],
    ['reservations', 'status', ReservationStatus::class],
    ['reservations', 'channel', ReservationChannel::class],
    ['reservation_price_lines', 'kind', PriceLineKind::class],
    ['quotes', 'status', QuoteStatus::class],
    ['payments', 'status', PaymentStatus::class],
    ['payments', 'purpose', PaymentPurpose::class],
    ['payments', 'capture_method', CaptureMethod::class],
    ['refunds', 'status', RefundStatus::class],
    ['refunds', 'method', RefundMethod::class],
    ['inventory_occupancies', 'kind', OccupancyKind::class],
    ['inventory_occupancies', 'phase', OccupancyPhase::class],
    ['inventory_occupancies', 'status', OccupancyStatus::class],
    ['inventory_conflicts', 'status', ConflictStatus::class],
    ['ics_feeds', 'status', IcsFeedStatus::class],
    ['ics_feeds', 'destination', ChannelSource::class],
    ['external_calendars', 'status', ExternalCalendarStatus::class],
    ['external_calendar_events', 'status', ExternalEventStatus::class],
    ['external_calendar_events', 'echo_kind', EchoKind::class],
    ['webhook_events', 'status', WebhookEventStatus::class],
    ['outbox_messages', 'status', OutboxStatus::class],
    ['audit_log', 'actor_type', AuditActorType::class],
    ['retention_policies', 'category', RetentionCategory::class],
    ['retention_policies', 'action', RetentionAction::class],
]);

it('rifiuta uno stato fuori dall\'enum', function () {
    $tenant = createTenantRow();
    $property = createPropertyRow($tenant);

    inTenant($tenant, fn () => DB::table('properties')->where('id', $property)->update(['status' => 'INVENTATO']));
})->throws(QueryException::class, 'properties_status_check');

it('rifiuta un soggiorno vuoto o con limiti inclusi in modo errato', function (string $stay) {
    $tenant = createTenantRow();
    $unit = createUnitRow($tenant, createPropertyRow($tenant));
    $guest = createGuestRow($tenant);

    inTenant($tenant, fn () => DB::table('reservations')->insert(reservationRow($tenant, $unit, $guest, ['stay' => $stay])));
})->with(['empty', '[2027-08-10,)', '(,2027-08-10)'])->throws(QueryException::class);

it('normalizza i soggiorni nella forma [check_in, check_out)', function () {
    $tenant = createTenantRow();
    $unit = createUnitRow($tenant, createPropertyRow($tenant));
    $guest = createGuestRow($tenant);
    $row = reservationRow($tenant, $unit, $guest, ['stay' => '[2027-08-10,2027-08-16]']);

    $stored = inTenant($tenant, function () use ($row) {
        DB::table('reservations')->insert($row);

        return DB::table('reservations')->where('id', $row['id'])->value('stay');
    });

    expect($stored)->toBe('[2027-08-10,2027-08-17)');
});

it('richiede caparra più saldo uguale al totale', function () {
    $tenant = createTenantRow();
    $unit = createUnitRow($tenant, createPropertyRow($tenant));
    $guest = createGuestRow($tenant);

    inTenant($tenant, fn () => DB::table('reservations')->insert(
        reservationRow($tenant, $unit, $guest, ['deposit_due_cents' => 3000, 'balance_due_cents' => 6999])
    ));
})->throws(QueryException::class, 'reservations_amounts_valid');

it('rende l\'email degli utenti univoca senza distinzione di maiuscole', function () {
    createUserRow('Proprietario@Example.test');
    createUserRow('proprietario@example.TEST');
})->throws(QueryException::class, 'users_email_lower_uniq');
