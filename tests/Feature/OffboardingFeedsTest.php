<?php

use App\Domain\Calendar\Enums\ChannelSource;
use App\Domain\Calendar\Enums\ExternalCalendarStatus;
use App\Domain\Calendar\Enums\IcsFeedStatus;
use App\Domain\Calendar\IcsFeedLifecycle;
use App\Domain\Calendar\Models\ExternalCalendar;
use App\Domain\Calendar\Models\IcsFeed;
use App\Domain\Inventory\Models\Unit;
use App\Domain\Reservations\Enums\ReservationStatus;
use App\Domain\Tenancy\Audit\AuditActor;
use App\Domain\Tenancy\Enums\TenantStatus;
use App\Domain\Tenancy\Models\OutboxMessage;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\Offboarding\Jobs\DispatchOffboardingChecksJob;
use App\Domain\Tenancy\Offboarding\Jobs\RunOffboardingChecksJob;
use App\Domain\Tenancy\Offboarding\OffboardingService;
use App\Domain\Tenancy\TenantContext;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

/*
 * AC18 (01, 06 "Ciclo di vita dei feed e uscita del cliente", 09 "Uscita del cliente", 7.1): all'avvio
 * dell'uscita le vendite si chiudono e i feed passano a FROZEN (mai vuoti, I19); un feed si ritira solo
 * dopo la conferma del gestore o, dopo 30 giorni, se non è letto da 7 giorni; un feed ancora letto resta
 * FROZEN e il gestore riceve un avviso. Pannello in sola lettura durante l'uscita.
 */

function offboardingFeed(array $fx, string $destination = 'BOOKING'): array
{
    $feed = inTenant($fx['tenant'], fn () => app(IcsFeedLifecycle::class)->create(Unit::query()->findOrFail($fx['unit']), ChannelSource::from($destination)));

    return [$feed, $feed->token_encrypted];
}

function startOffboarding(array $fx): void
{
    inTenant($fx['tenant'], fn () => app(OffboardingService::class)->start(null, AuditActor::system()));
}

function feedStatus(array $fx, string $feedId): IcsFeedStatus
{
    return inTenant($fx['tenant'], fn () => IcsFeed::query()->findOrFail($feedId)->status);
}

function markFeedRead(array $fx, string $feedId, CarbonInterface $at): void
{
    inTenant($fx['tenant'], fn () => IcsFeed::query()->whereKey($feedId)->toBase()->update(['last_fetched_at' => $at]));
}

it('avvio: tenant OFFBOARDING, pagina ospite chiusa, feed FROZEN che servono ancora le prenotazioni esistenti', function () {
    $fx = bookableTenant();
    reservationIn($fx, ReservationStatus::CONFIRMED, '[2027-07-10,2027-07-14)');
    [$feed, $token] = offboardingFeed($fx);

    $owner = signedInOwner($this, $fx['tenant'], passwordConfirmed: true);
    panelPost($this, '/api/v1/admin/tenant/offboarding/start')->assertOk()->assertJsonPath('data.status', 'OFFBOARDING');

    expect(inTenant($fx['tenant'], fn () => Tenant::query()->firstOrFail()->status))->toBe(TenantStatus::OFFBOARDING)
        ->and(feedStatus($fx, $feed->id))->toBe(IcsFeedStatus::FROZEN)
        ->and($owner['tenant'])->toBe($fx['tenant']);

    $this->getJson("/api/v1/public/{$fx['slug']}/{$fx['property_slug']}")->assertNotFound();
    $this->get("/ical/{$token}.ics")->assertOk()->assertSee('DTSTART;VALUE=DATE:20270710', false);
});

it('avvio con la ri-autenticazione', function () {
    $fx = bookableTenant();
    signedInOwner($this, $fx['tenant']);

    panelPost($this, '/api/v1/admin/tenant/offboarding/start')->assertStatus(403)->assertJsonPath('error.code', 'REAUTH_REQUIRED');
    expect(inTenant($fx['tenant'], fn () => Tenant::query()->firstOrFail()->status))->toBe(TenantStatus::ACTIVE);
});

it('la pagina mostra per ogni feed l\'ultima lettura dell\'OTA', function () {
    // Orario fermo: l'atteso si ricalcola con now() a fine test.
    $this->freezeSecond();
    $fx = bookableTenant();
    [$feed] = offboardingFeed($fx);
    markFeedRead($fx, $feed->id, now()->subDays(2));
    startOffboarding($fx);
    signedInOwner($this, $fx['tenant']);

    panelGet($this, '/api/v1/admin/tenant/offboarding')
        ->assertOk()
        ->assertJsonPath('data.status', 'OFFBOARDING')
        ->assertJsonPath('data.feeds.0.id', $feed->id)
        ->assertJsonPath('data.feeds.0.status', 'FROZEN')
        ->assertJsonPath('data.feeds.0.read_recently', true)
        ->assertJsonPath('data.feeds.0.last_fetched_at', now()->subDays(2)->toImmutable()->toIso8601ZuluString());
});

it('conferma della rimozione dall\'OTA: il feed passa a RETIRED (410); con tutti i feed ritirati i calendari importati si disattivano', function () {
    $fx = bookableTenant('REQUEST');
    reservationIn($fx, ReservationStatus::CONFIRMED);
    [$booking, $bookingToken] = offboardingFeed($fx, 'BOOKING');
    [$airbnb] = offboardingFeed($fx, 'AIRBNB');
    $calendar = createCalendar($fx['tenant'], $fx['unit']);
    startOffboarding($fx);
    signedInOwner($this, $fx['tenant'], passwordConfirmed: true);

    panelPost($this, "/api/v1/admin/tenant/offboarding/channels/{$booking->id}/confirm-removed")->assertOk();

    expect(feedStatus($fx, $booking->id))->toBe(IcsFeedStatus::RETIRED)
        ->and(feedStatus($fx, $airbnb->id))->toBe(IcsFeedStatus::FROZEN)
        ->and(inTenant($fx['tenant'], fn () => Tenant::query()->firstOrFail()->feeds_disabled_at))->toBeNull();
    $this->get("/ical/{$bookingToken}.ics")->assertStatus(410)->assertJsonPath('error.code', 'FEED_RETIRED');

    panelPost($this, "/api/v1/admin/tenant/offboarding/channels/{$airbnb->id}/confirm-removed")->assertOk();

    $tenant = inTenant($fx['tenant'], fn () => Tenant::query()->firstOrFail());
    expect($tenant->feeds_disabled_at)->not->toBeNull()
        ->and($tenant->channels_disconnected_confirmed_at)->not->toBeNull()
        ->and(inTenant($fx['tenant'], fn () => ExternalCalendar::query()->findOrFail($calendar->id)->status))->toBe(ExternalCalendarStatus::DISABLED);
});

it('la conferma richiede l\'uscita avviata', function () {
    $fx = bookableTenant();
    [$feed] = offboardingFeed($fx);
    signedInOwner($this, $fx['tenant'], passwordConfirmed: true);

    panelPost($this, "/api/v1/admin/tenant/offboarding/channels/{$feed->id}/confirm-removed")
        ->assertStatus(409)->assertJsonPath('error.code', 'TENANT_NOT_OFFBOARDING');
    expect(feedStatus($fx, $feed->id))->toBe(IcsFeedStatus::ACTIVE);
});

it('ritiro automatico: prima dei 30 giorni nessun ritiro; dopo, solo i feed non letti da 7 giorni', function () {
    $fx = bookableTenant();
    reservationIn($fx, ReservationStatus::CONFIRMED);
    [$silent, $silentToken] = offboardingFeed($fx, 'BOOKING');
    [$read, $readToken] = offboardingFeed($fx, 'AIRBNB');
    startOffboarding($fx);

    $this->travel(29)->days();
    markFeedRead($fx, $read->id, now());
    markFeedRead($fx, $silent->id, now()->subDays(20));
    (new RunOffboardingChecksJob($fx['tenant']))->handle(app(TenantContext::class), app(OffboardingService::class));

    expect(feedStatus($fx, $silent->id))->toBe(IcsFeedStatus::FROZEN)->and(feedStatus($fx, $read->id))->toBe(IcsFeedStatus::FROZEN);

    $this->travel(2)->days();
    markFeedRead($fx, $read->id, now()->subDay());
    (new RunOffboardingChecksJob($fx['tenant']))->handle(app(TenantContext::class), app(OffboardingService::class));

    expect(feedStatus($fx, $silent->id))->toBe(IcsFeedStatus::RETIRED)
        ->and(feedStatus($fx, $read->id))->toBe(IcsFeedStatus::FROZEN);
    $this->get("/ical/{$silentToken}.ics")->assertStatus(410);
    $this->get("/ical/{$readToken}.ics")->assertOk()->assertSee('BEGIN:VEVENT', false);

    // Il feed ancora letto non si ritira in silenzio: avviso al gestore, una volta sola.
    (new RunOffboardingChecksJob($fx['tenant']))->handle(app(TenantContext::class), app(OffboardingService::class));
    $messages = inTenant($fx['tenant'], fn () => OutboxMessage::query()->where('type', 'offboarding.feed_still_read')->get());
    expect($messages)->toHaveCount(1)->and($messages->first()->payload)->toBe(['ics_feed_id' => $read->id])
        ->and(inTenant($fx['tenant'], fn () => Tenant::query()->firstOrFail()->feeds_disabled_at))->toBeNull();
});

it('ritiro automatico di un feed mai letto dopo 30 giorni', function () {
    $fx = bookableTenant();
    [$feed] = offboardingFeed($fx);
    startOffboarding($fx);
    $this->travel(31)->days();

    $result = inTenant($fx['tenant'], fn () => app(OffboardingService::class)->retireUnreadFeeds(now()->toImmutable()));

    expect($result)->toBe(['retired' => 1, 'still_read' => 0])
        ->and(feedStatus($fx, $feed->id))->toBe(IcsFeedStatus::RETIRED)
        ->and(inTenant($fx['tenant'], fn () => Tenant::query()->firstOrFail()->channels_disconnected_confirmed_at))->toBeNull()
        ->and(inTenant($fx['tenant'], fn () => Tenant::query()->firstOrFail()->feeds_disabled_at))->not->toBeNull();
});

it('il dispatcher notturno (app_admin_ops) accoda un job solo per i tenant in uscita', function () {
    Queue::fake();
    $offboarding = committedTenantWithUnit();
    $active = committedTenantWithUnit();

    try {
        DB::connection(committedConnection('pgsql_migrator', $offboarding['tenant']))
            ->table('tenants')->where('id', $offboarding['tenant'])->update(['status' => 'OFFBOARDING', 'offboarding_started_at' => now()]);

        app()->call([new DispatchOffboardingChecksJob, 'handle']);

        Queue::assertPushed(RunOffboardingChecksJob::class, fn (RunOffboardingChecksJob $job) => $job->tenantId === $offboarding['tenant']);
        Queue::assertNotPushed(RunOffboardingChecksJob::class, fn (RunOffboardingChecksJob $job) => $job->tenantId === $active['tenant']);
    } finally {
        deleteCommittedTenant($offboarding['tenant']);
        deleteCommittedTenant($active['tenant']);
    }
});

it('durante l\'uscita il pannello è in sola lettura, salvo cancellazioni, rimborsi ed export', function () {
    $fx = bookableTenant();
    $reservation = reservationIn($fx, ReservationStatus::CONFIRMED);
    startOffboarding($fx);
    signedInOwner($this, $fx['tenant'], passwordConfirmed: true);

    panelGet($this, "/api/v1/admin/properties/{$fx['property']}")->assertOk();
    panelPatch($this, "/api/v1/admin/properties/{$fx['property']}", ['name' => 'Nuovo nome'], 1)
        ->assertStatus(409)->assertJsonPath('error.code', 'TENANT_OFFBOARDING');
    panelPost($this, "/api/v1/admin/properties/{$fx['property']}/units", ['name' => 'Altra'])
        ->assertStatus(409)->assertJsonPath('error.code', 'TENANT_OFFBOARDING');

    panelPost($this, "/api/v1/admin/reservations/{$reservation->id}/cancel", ['reason' => 'uscita'])->assertSuccessful();
    panelPost($this, '/api/v1/admin/tenant/export')->assertStatus(202);
});

it('preventivo di un tenant in uscita → 404', function () {
    $fx = bookableTenant();
    $quote = quoteFor($fx);
    $token = quoteToken($quote);

    $this->getJson("/api/v1/public/quotes/{$token}")->assertOk();
    startOffboarding($fx);

    $this->getJson("/api/v1/public/quotes/{$token}")->assertNotFound()->assertJsonPath('error.code', 'NOT_FOUND');
    acceptQuote($this, $token)->assertNotFound();
});
