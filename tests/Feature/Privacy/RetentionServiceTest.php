<?php

use App\Domain\Privacy\Enums\RetentionCategory;
use App\Domain\Privacy\Jobs\DispatchRetentionJob;
use App\Domain\Privacy\Jobs\RunGlobalRetentionJob;
use App\Domain\Privacy\Jobs\RunTenantRetentionJob;
use App\Domain\Privacy\Models\RetentionPolicy;
use App\Domain\Privacy\RetentionDatabase;
use App\Domain\Privacy\RetentionService;
use App\Domain\Reservations\Enums\ReservationStatus;
use App\Domain\Tenancy\Enums\TenantExportStatus;
use App\Domain\Tenancy\Models\TenantExport;
use App\Domain\Tenancy\Offboarding\OffboardingService;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

/*
 * Job di conservazione (7.2; 09 "Conservazione", ADR-0018): le categorie non validate girano in
 * simulazione (conteggi nell'audit, nessuna modifica); quelle validate anonimizzano (B, D, su tutte le
 * copie: I22) o cancellano con il ruolo app_retention.
 */

function retention(): RetentionService
{
    return app(RetentionService::class);
}

function validateCategory(RetentionCategory $category): void
{
    retention()->validate($category, 'Avv. Prova', now()->toImmutable()->subDay());
}

function tenantCount(string $tenant, string $table, array $where = []): int
{
    return inTenant($tenant, fn () => DB::table($table)->where($where)->count());
}

function oldAuditRow(?string $tenant, string $occurredAt): void
{
    $insert = fn () => DB::table('audit_log')->insert([
        'id' => uuid7(), 'tenant_id' => $tenant, 'actor_type' => 'SYSTEM', 'action' => 'prova.vecchia',
        'subject_type' => 'tenant', 'changes' => '{}', 'occurred_at' => $occurredAt,
    ]);

    $tenant === null ? $insert() : inTenant($tenant, $insert);
}

function sentOutboxRow(string $tenant, string $status, ?string $sentAt): void
{
    inTenant($tenant, fn () => DB::table('outbox_messages')->insert([
        'id' => uuid7(), 'tenant_id' => $tenant, 'type' => 'reservation.confirmed', 'dedupe_key' => 'prova:'.uuid7(),
        'payload' => '{}', 'status' => $status, 'sent_at' => $sentAt, 'available_at' => now(), 'created_at' => now(), 'updated_at' => now(),
    ]));
}

it('ha le categorie A–H con i valori provvisori di 09, nessuna validata', function () {
    $policies = retention()->policies();

    expect(array_keys($policies))->toEqualCanonicalizing(array_map(fn (RetentionCategory $c) => $c->value, RetentionCategory::cases()))
        ->and($policies['A_ADMINISTRATIVE']->retention_days)->toBe(3650)
        ->and($policies['B_GUEST_IDENTITY']->retention_days)->toBe(730)
        ->and($policies['D_UNCONCLUDED_ATTEMPTS']->retention_days)->toBe(90)
        ->and($policies['E_IMPORTED_EVENTS']->retention_days)->toBe(30)
        ->and($policies['F_SECURITY_AUDIT']->retention_days)->toBe(730)
        ->and($policies['G_COMMUNICATIONS']->retention_days)->toBe(30)
        ->and(collect($policies)->every(fn (RetentionPolicy $p) => $p->validated_at === null))->toBeTrue();
});

it('simulazione: categorie non validate, conteggi nell\'audit e nessuna modifica', function () {
    $fx = bookableTenant();
    $reservation = reservationIn($fx, ReservationStatus::CONFIRMED, '[2027-07-10,2027-07-14)');
    sentOutboxRow($fx['tenant'], 'SENT', '2027-07-01 10:00:00');
    oldAuditRow($fx['tenant'], '2027-01-01 10:00:00');
    $this->travelTo('2038-01-01 03:00:00');

    $report = retention()->runTenant($fx['tenant'], now()->toImmutable());

    expect($report['B_GUEST_IDENTITY'])->toBe(['mode' => 'simulation', 'days' => 730, 'rows' => 1])
        ->and($report['G_COMMUNICATIONS']['rows'])->toBeGreaterThanOrEqual(1)
        ->and($report['F_SECURITY_AUDIT']['rows'])->toBeGreaterThanOrEqual(1)
        ->and($report['C_PAYMENT_REFERENCES']['rows'])->toBe(1)
        ->and($report['A_ADMINISTRATIVE'])->toBe(['mode' => 'simulation', 'days' => 3650, 'rows' => 1])
        ->and(tenantCount($fx['tenant'], 'reservations'))->toBe(1)
        ->and(tenantCount($fx['tenant'], 'payments'))->toBe(1)
        ->and(tenantCount($fx['tenant'], 'outbox_messages', ['status' => 'SENT']))->toBeGreaterThanOrEqual(1)
        ->and(tenantCount($fx['tenant'], 'audit_log', ['action' => 'prova.vecchia']))->toBe(1)
        ->and(inTenant($fx['tenant'], fn () => DB::table('guests')->where('id', $reservation->guest_id)->value('anonymized_at')))->toBeNull();

    $audit = inTenant($fx['tenant'], fn () => DB::table('audit_log')->where('action', 'retention.run')->sole());
    expect(json_decode($audit->changes, true)['A_ADMINISTRATIVE']['mode'])->toBe('simulation');
});

it('H validata: cancella i link di reimpostazione usati o scaduti da oltre 30 giorni, una volta per esecuzione', function () {
    $user = createUserRow();
    $row = fn (array $values) => DB::table('user_password_resets')->insert([
        'id' => uuid7(), 'user_id' => $user, 'token_hash' => hash('sha256', uuid7()), 'created_at' => now(), 'updated_at' => now(), ...$values,
    ]);
    $row(['expires_at' => now()->subDays(40), 'used_at' => null]);
    $row(['expires_at' => now()->subDays(45), 'used_at' => now()->subDays(41)]);
    $row(['expires_at' => now()->subDays(10), 'used_at' => null]);
    $row(['expires_at' => now()->addMinutes(30), 'used_at' => null]);

    expect(retention()->runGlobal(now()->toImmutable())['H_PASSWORD_RESET_LINKS'])->toBe(['mode' => 'simulation', 'days' => 30, 'rows' => 2])
        ->and(DB::table('user_password_resets')->count())->toBe(4);

    validateCategory(RetentionCategory::H_PASSWORD_RESET_LINKS);

    expect(retention()->runGlobal(now()->toImmutable())['H_PASSWORD_RESET_LINKS'])->toBe(['mode' => 'applied', 'days' => 30, 'rows' => 2])
        ->and(DB::table('user_password_resets')->count())->toBe(2);
});

it('B validata (I22): anonimizza su tutte le copie gli ospiti con l\'ultimo check-out oltre 24 mesi', function () {
    Storage::fake('local');
    config(['offboarding.disk' => 'local']);
    $fx = bookableTenant();
    $old = reservationIn($fx, ReservationStatus::CONFIRMED, '[2027-07-10,2027-07-14)');
    inTenant($fx['tenant'], fn () => app(OffboardingService::class)->requestExport(null));
    $this->travelTo('2029-07-20 03:00:00');
    validateCategory(RetentionCategory::B_GUEST_IDENTITY);

    $report = retention()->runTenant($fx['tenant'], now()->toImmutable());

    $guest = inTenant($fx['tenant'], fn () => DB::table('guests')->where('id', $old->guest_id)->first());
    expect($report['B_GUEST_IDENTITY'])->toBe(['mode' => 'applied', 'days' => 730, 'rows' => 1])
        ->and($guest->anonymized_at)->not->toBeNull()
        ->and($guest->last_name)->toBe('[rimosso]')
        ->and($guest->email)->not->toContain('example.test')
        ->and(inTenant($fx['tenant'], fn () => TenantExport::query()->sole()->status))->toBe(TenantExportStatus::EXPIRED)
        ->and(tenantCount($fx['tenant'], 'reservations'))->toBe(1);
});

it('B non tocca gli ospiti con un soggiorno più recente della durata', function () {
    $fx = bookableTenant();
    $reservation = reservationIn($fx, ReservationStatus::CONFIRMED, '[2027-07-10,2027-07-14)');
    $this->travelTo('2029-07-01 03:00:00');
    validateCategory(RetentionCategory::B_GUEST_IDENTITY);

    expect(retention()->runTenant($fx['tenant'], now()->toImmutable())['B_GUEST_IDENTITY']['rows'])->toBe(0)
        ->and(inTenant($fx['tenant'], fn () => DB::table('guests')->where('id', $reservation->guest_id)->value('anonymized_at')))->toBeNull();
});

it('D validata: tentativi non conclusi e preventivi non accettati oltre 90 giorni senza dati personali', function () {
    $fx = bookableTenant();
    $expired = reservationIn($fx, ReservationStatus::EXPIRED, '[2027-07-10,2027-07-14)');
    $confirmed = reservationIn($fx, ReservationStatus::CONFIRMED, '[2027-07-20,2027-07-24)');
    $quote = quoteFor($fx);
    inTenant($fx['tenant'], fn () => DB::table('quotes')->where('id', $quote->id)->update(['status' => 'REVOKED']));
    $this->travel(100)->days();
    validateCategory(RetentionCategory::D_UNCONCLUDED_ATTEMPTS);

    $report = retention()->runTenant($fx['tenant'], now()->toImmutable());

    expect($report['D_UNCONCLUDED_ATTEMPTS'])->toBe(['mode' => 'applied', 'days' => 90, 'rows' => 2])
        ->and(inTenant($fx['tenant'], fn () => DB::table('guests')->where('id', $expired->guest_id)->value('anonymized_at')))->not->toBeNull()
        ->and(inTenant($fx['tenant'], fn () => DB::table('guests')->where('id', $confirmed->guest_id)->value('anonymized_at')))->toBeNull()
        ->and(inTenant($fx['tenant'], fn () => DB::table('quotes')->where('id', $quote->id)->first(['guest_name', 'guest_email'])))
        ->toEqual((object) ['guest_name' => '[rimosso]', 'guest_email' => null]);
});

it('G validata: cancella i messaggi inviati o falliti da oltre 30 giorni, non quelli in attesa', function () {
    $fx = bookableTenant();
    sentOutboxRow($fx['tenant'], 'SENT', now()->subDays(40)->toDateTimeString());
    sentOutboxRow($fx['tenant'], 'SENT', now()->subDays(5)->toDateTimeString());
    sentOutboxRow($fx['tenant'], 'PENDING', null);
    validateCategory(RetentionCategory::G_COMMUNICATIONS);
    $before = tenantCount($fx['tenant'], 'outbox_messages');

    $rows = retention()->runTenant($fx['tenant'], now()->toImmutable())['G_COMMUNICATIONS']['rows'];

    expect($rows)->toBe(1)->and(tenantCount($fx['tenant'], 'outbox_messages'))->toBe($before - 1);
});

it('E validata: cancella gli eventi importati con check-out oltre 30 giorni, salvo quelli con un conflitto aperto', function () {
    $tenant = createTenantRow();
    $unit = goldenUnitRows($tenant)['unit'];
    $old = createExternalEventRow($tenant, $unit, '[2026-08-01,2026-08-05)');
    $recent = createExternalEventRow($tenant, $unit, '['.now()->subDays(10)->toDateString().','.now()->subDays(5)->toDateString().')');
    $openConflict = createConflictRow($tenant, $unit, '[2026-08-10,2026-08-12)', 'OPEN');
    $closedConflict = createConflictRow($tenant, $unit, '[2026-08-20,2026-08-22)', 'RESOLVED');
    validateCategory(RetentionCategory::E_IMPORTED_EVENTS);

    $rows = retention()->runTenant($tenant, now()->toImmutable())['E_IMPORTED_EVENTS']['rows'];

    $events = inTenant($tenant, fn () => DB::table('external_calendar_events')->pluck('id')->all());
    expect($rows)->toBe(2)
        ->and($events)->toContain($recent)->not->toContain($old)
        ->and(tenantCount($tenant, 'inventory_conflicts', ['id' => $openConflict]))->toBe(1)
        ->and(tenantCount($tenant, 'inventory_conflicts', ['id' => $closedConflict]))->toBe(0);
});

it('F validata: audit oltre 24 mesi cancellato per tenant e, una volta per esecuzione, per la piattaforma', function () {
    $fx = bookableTenant();
    oldAuditRow($fx['tenant'], now()->subDays(800)->toDateTimeString());
    oldAuditRow($fx['tenant'], now()->subDays(100)->toDateTimeString());
    oldAuditRow(null, now()->subDays(800)->toDateTimeString());
    validateCategory(RetentionCategory::F_SECURITY_AUDIT);

    expect(retention()->runTenant($fx['tenant'], now()->toImmutable())['F_SECURITY_AUDIT']['rows'])->toBe(1)
        ->and(tenantCount($fx['tenant'], 'audit_log', ['action' => 'prova.vecchia']))->toBe(1)
        ->and(retention()->runGlobal(now()->toImmutable())['F_SECURITY_AUDIT']['rows'])->toBe(1)
        ->and(app(RetentionDatabase::class)->run(null, fn (ConnectionInterface $db) => $db->table('audit_log')->whereNull('tenant_id')->where('action', 'prova.vecchia')->count()))->toBe(0);
});

it('A e C validate: dopo 10 anni cancellano prenotazione, pagamenti, occupazioni, preventivo e ospite', function () {
    $fx = bookableTenant();
    $old = reservationIn($fx, ReservationStatus::CONFIRMED, '[2027-07-10,2027-07-14)');
    $quote = quoteFor($fx, ['stay' => '[2027-07-20,2027-07-24)']);
    $this->travelTo('2037-08-01 03:00:00');
    validateCategory(RetentionCategory::A_ADMINISTRATIVE);
    validateCategory(RetentionCategory::C_PAYMENT_REFERENCES);

    $report = retention()->runTenant($fx['tenant'], now()->toImmutable());

    expect($report['C_PAYMENT_REFERENCES']['rows'])->toBe(1)
        ->and($report['A_ADMINISTRATIVE']['rows'])->toBe(1);

    foreach (['reservations', 'payments', 'reservation_price_lines', 'guests', 'inventory_occupancies'] as $table) {
        expect(tenantCount($fx['tenant'], $table))->toBe(0, $table);
    }

    expect(tenantCount($fx['tenant'], 'quotes', ['id' => $quote->id]))->toBe(1)
        ->and($old->id)->toBeString();
});

it('app_retention non può modificare righe', function () {
    $fx = bookableTenant();
    reservationIn($fx, ReservationStatus::CONFIRMED);

    app(RetentionDatabase::class)->run($fx['tenant'], fn (ConnectionInterface $db) => $db->table('reservations')->update(['adults' => 3]));
})->throws(QueryException::class, 'permission denied');

it('il dispatcher notturno accoda le categorie globali e un job per tenant', function () {
    Queue::fake();
    $fx = committedTenantWithUnit();

    try {
        app()->call([new DispatchRetentionJob, 'handle']);

        Queue::assertPushed(RunGlobalRetentionJob::class, 1);
        Queue::assertPushed(RunTenantRetentionJob::class, fn (RunTenantRetentionJob $job) => $job->tenantId === $fx['tenant']);
    } finally {
        deleteCommittedTenant($fx['tenant']);
    }
});

it('comando: stato, validazione con nome e data, esecuzione', function () {
    $fx = bookableTenant();

    $this->artisan('retention', ['action' => 'status'])->expectsOutputToContain('non validata: simulazione')->assertSuccessful();
    $this->artisan('retention', ['action' => 'validate', 'category' => 'Z', '--by' => 'Avv. Prova', '--date' => '2026-11-01'])->assertFailed();
    $this->artisan('retention', ['action' => 'validate', 'category' => 'H', '--by' => 'x', '--date' => '2026-11-01'])->assertFailed();
    $this->artisan('retention', ['action' => 'validate', 'category' => 'H', '--by' => 'Avv. Prova', '--date' => '2099-01-01'])->assertFailed();

    $this->artisan('retention', ['action' => 'validate', 'category' => 'H', '--by' => 'Avv. Prova', '--date' => '2026-11-01', '--days' => '45'])
        ->expectsConfirmation(__('privacy.retention.confirm_validate'), 'yes')
        ->assertSuccessful();

    $policy = RetentionPolicy::query()->where('category', 'H_PASSWORD_RESET_LINKS')->sole();
    expect($policy->validated_by)->toBe('Avv. Prova')
        ->and($policy->validated_at?->toDateString())->toBe('2026-11-01')
        ->and($policy->retention_days)->toBe(45);

    $this->artisan('retention', ['action' => 'run', '--tenant' => $fx['slug']])->expectsOutputToContain('B_GUEST_IDENTITY')->assertSuccessful();
});
