<?php

use App\Domain\Calendar\Enums\ChannelSource;
use App\Domain\Calendar\IcsFeedLifecycle;
use App\Domain\Calendar\Models\IcsFeed;
use App\Domain\Inventory\Models\Unit;
use App\Domain\Reservations\Enums\ReservationStatus;
use App\Domain\Tenancy\Audit\AuditActor;
use App\Domain\Tenancy\Enums\TenantStatus;
use App\Domain\Tenancy\Exceptions\BusinessRuleViolation;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\Models\TenantExport;
use App\Domain\Tenancy\Offboarding\OffboardingService;
use App\Domain\Tenancy\Offboarding\TenantDataPurger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/*
 * Cancellazione dei dati secondo il DPA (7.1; 09 "Uscita del cliente" punto 6, ADR-0018): solo con il
 * tenant OFFBOARDING, tutti i feed ritirati e 30 giorni dall'export (o un'istruzione scritta); righe di
 * tutte le tabelle del tenant con il ruolo app_retention, utenti solo di questo tenant, foto ed export;
 * restano la riga del tenant (CLOSED) e l'audit log.
 */

beforeEach(function () {
    Storage::fake('local');
    config(['offboarding.disk' => 'local']);
});

function purgeService(): OffboardingService
{
    return app(OffboardingService::class);
}

/** Tenant con dati in quasi tutte le tabelle, già in uscita, con feed ritirato e un export pronto. */
function tenantReadyToPurge(): array
{
    $fx = bookableTenant();
    reservationIn($fx, ReservationStatus::CONFIRMED, '[2027-07-20,2027-07-24)');
    reservationIn($fx, ReservationStatus::CANCELLED, '[2027-07-25,2027-07-28)');
    quoteFor($fx);
    inTenant($fx['tenant'], fn () => DB::table('units')->where('id', $fx['unit'])->update(['booking_mode' => 'REQUEST']));
    createCalendar($fx['tenant'], $fx['unit']);
    $owner = panelUser($fx['tenant']);

    inTenant($fx['tenant'], function () use ($fx) {
        Storage::disk('local')->put('photos/a.jpg', 'x');
        Storage::disk('local')->put('photos/a-thumb.jpg', 'x');
        DB::table('unit_photos')->insert([
            'id' => uuid7(), 'tenant_id' => $fx['tenant'], 'unit_id' => $fx['unit'], 'disk' => 'local', 'path' => 'photos/a.jpg',
            'thumbnail_path' => 'photos/a-thumb.jpg', 'mime_type' => 'image/jpeg', 'size_bytes' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $feed = app(IcsFeedLifecycle::class)->create(Unit::query()->findOrFail($fx['unit']), ChannelSource::BOOKING);
        purgeService()->start(null, AuditActor::system());
        purgeService()->requestExport(null);
        purgeService()->confirmFeedRemoved($feed->id);
    });

    return [...$fx, 'owner' => $owner['user']->id];
}

function tenantRowCounts(string $tenantId): array
{
    return inTenant($tenantId, function () use ($tenantId): array {
        $counts = [];

        foreach ([...TenantDataPurger::PURGE_ORDER, ...TenantDataPurger::KEPT] as $table) {
            $counts[$table] = DB::table($table)->where('tenant_id', $tenantId)->count();
        }

        return $counts;
    });
}

function purgeRefusal(callable $fn): array
{
    try {
        $fn();
    } catch (BusinessRuleViolation $e) {
        return [$e->errorCode, $e->details];
    }

    return [null, []];
}

it('PURGE_ORDER e KEPT coprono tutte le tabelle con tenant_id', function () {
    $tables = collect(DB::select("select table_name from information_schema.columns where table_schema = 'public' and column_name = 'tenant_id'"))
        ->pluck('table_name')->sort()->values()->all();
    $covered = collect([...TenantDataPurger::PURGE_ORDER, ...TenantDataPurger::KEPT])->sort()->values()->all();

    expect($covered)->toBe($tables)
        ->and(array_unique(TenantDataPurger::PURGE_ORDER))->toHaveCount(count(TenantDataPurger::PURGE_ORDER));
});

it('cancella tutti i dati del tenant con app_retention; restano tenant CLOSED e audit log; l\'altro tenant è intatto', function () {
    $fx = tenantReadyToPurge();
    $other = bookableTenant();
    reservationIn($other, ReservationStatus::CONFIRMED);
    $otherBefore = tenantRowCounts($other['tenant']);
    $export = inTenant($fx['tenant'], fn () => TenantExport::query()->firstOrFail());
    $before = tenantRowCounts($fx['tenant']);

    expect($before['reservations'])->toBe(2)->and($before['guests'])->toBeGreaterThan(0)->and($before['quotes'])->toBe(1)
        ->and($before['unit_photos'])->toBe(1)->and($before['tenant_members'])->toBe(1);

    $this->travel(31)->days();
    $counts = purgeService()->purge($fx['tenant'], null, now()->toImmutable());

    $after = tenantRowCounts($fx['tenant']);
    expect(array_sum(array_diff_key($after, ['audit_log' => 0])))->toBe(0)
        ->and($after['audit_log'])->toBeGreaterThan(0)
        ->and($counts['users'])->toBe(1)
        ->and($counts['files'])->toBe(3)
        ->and(DB::table('users')->where('id', $fx['owner'])->exists())->toBeFalse()
        ->and(tenantRowCounts($other['tenant']))->toBe($otherBefore);

    Storage::disk('local')->assertMissing(['photos/a.jpg', 'photos/a-thumb.jpg', (string) $export->file_path]);

    $tenant = inTenant($fx['tenant'], fn () => Tenant::query()->firstOrFail());
    expect($tenant->status)->toBe(TenantStatus::CLOSED)->and($tenant->data_purged_at)->not->toBeNull();

    $audit = inTenant($fx['tenant'], fn () => DB::table('audit_log')->where('action', 'tenant.data_purged')->first());
    expect($audit)->not->toBeNull()->and($audit->changes)->not->toContain('@');
});

it('l\'utente che appartiene anche a un altro tenant resta, senza la membership', function () {
    $fx = tenantReadyToPurge();
    $other = createTenantRow();
    createMemberRow($other, $fx['owner']);

    $counts = purgeService()->purge($fx['tenant'], 'Richiesta scritta del cliente del 3 novembre', now()->toImmutable());

    expect($counts['users'])->toBe(0)
        ->and(DB::table('users')->where('id', $fx['owner'])->exists())->toBeTrue()
        ->and(inTenant($other, fn () => DB::table('tenant_members')->where('user_id', $fx['owner'])->count()))->toBe(1);
});

it('condizioni: tenant in uscita, feed ritirati, 30 giorni dall\'export oppure istruzione scritta', function () {
    $active = bookableTenant();
    expect(purgeRefusal(fn () => purgeService()->purge($active['tenant'], 'Istruzione scritta del cliente', now()->toImmutable())))
        ->toBe(['TENANT_NOT_OFFBOARDING', ['status' => 'ACTIVE']]);

    // Feed ancora FROZEN: nemmeno l'istruzione scritta basta (I19).
    $frozen = bookableTenant();
    inTenant($frozen['tenant'], function () use ($frozen) {
        app(IcsFeedLifecycle::class)->create(Unit::query()->findOrFail($frozen['unit']), ChannelSource::BOOKING);
        purgeService()->start(null, AuditActor::system());
    });
    expect(purgeRefusal(fn () => purgeService()->purge($frozen['tenant'], 'Istruzione scritta del cliente', now()->toImmutable())))
        ->toBe(['OFFBOARDING_NOT_READY', ['missing' => 'feeds_retired']])
        ->and(inTenant($frozen['tenant'], fn () => IcsFeed::query()->count()))->toBe(1);

    // Feed ritirati ed export di 10 giorni fa: serve l'attesa o l'istruzione.
    $fx = tenantReadyToPurge();
    $this->travel(10)->days();
    expect(purgeRefusal(fn () => purgeService()->purge($fx['tenant'], null, now()->toImmutable())))
        ->toBe(['OFFBOARDING_NOT_READY', ['missing' => 'export_wait']]);

    purgeService()->purge($fx['tenant'], 'Istruzione scritta del cliente del 3 novembre', now()->toImmutable());
    expect(inTenant($fx['tenant'], fn () => Tenant::query()->firstOrFail()->status))->toBe(TenantStatus::CLOSED);
});

it('comando di piattaforma: stato, istruzione non valida rifiutata, cancellazione con conferma', function () {
    $fx = tenantReadyToPurge();

    $this->artisan('tenant:offboarding', ['slug' => $fx['slug'], 'action' => 'status'])->assertSuccessful();
    $this->artisan('tenant:offboarding', ['slug' => 'non-esiste', 'action' => 'status'])->assertFailed();
    $this->artisan('tenant:offboarding', ['slug' => $fx['slug'], 'action' => 'boh'])->assertFailed();
    $this->artisan('tenant:offboarding', ['slug' => $fx['slug'], 'action' => 'purge', '--instruction' => 'mario@example.test lo chiede'])->assertFailed();
    $this->artisan('tenant:offboarding', ['slug' => $fx['slug'], 'action' => 'purge'])
        ->expectsConfirmation(__('tenancy.offboarding.confirm_purge'), 'yes')
        ->expectsOutputToContain('OFFBOARDING_NOT_READY')
        ->assertFailed();

    $this->artisan('tenant:offboarding', ['slug' => $fx['slug'], 'action' => 'purge', '--instruction' => 'Richiesta scritta del cliente'])
        ->expectsConfirmation(__('tenancy.offboarding.confirm_purge'), 'yes')
        ->assertSuccessful();

    expect(inTenant($fx['tenant'], fn () => Tenant::query()->firstOrFail()->status))->toBe(TenantStatus::CLOSED);
});

it('comando di piattaforma: avvio con conferma', function () {
    $fx = bookableTenant();

    $this->artisan('tenant:offboarding', ['slug' => $fx['slug'], 'action' => 'start'])
        ->expectsConfirmation(__('tenancy.offboarding.confirm_start'), 'yes')
        ->assertSuccessful();

    expect(inTenant($fx['tenant'], fn () => Tenant::query()->firstOrFail()->status))->toBe(TenantStatus::OFFBOARDING);
});
