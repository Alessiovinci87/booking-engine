<?php

use App\Domain\Calendar\Enums\ChannelSource;
use App\Domain\Calendar\IcsFeedLifecycle;
use App\Domain\Calendar\Models\IcsFeed;
use App\Domain\Inventory\Models\Unit;
use App\Domain\Reservations\Enums\ReservationStatus;
use App\Domain\Tenancy\Models\AuditLog;
use Illuminate\Support\Facades\DB;

/*
 * 5.5 — GET /ical/{token}.ics e feed del pannello (06 "Formato" e "Ciclo di vita", 07, 08):
 * intestazioni, ETag e 304, token sconosciuto 404, registrazione delle letture al massimo ogni 5 minuti,
 * limite di 60 richieste all'ora per token, tenant ricavato dalla funzione SECURITY DEFINER, API admin.
 */

function feedWithToken(array $fx, string $destination = 'BOOKING'): array
{
    $feed = inTenant($fx['tenant'], fn () => app(IcsFeedLifecycle::class)->create(Unit::query()->findOrFail($fx['unit']), ChannelSource::from($destination)));

    return [$feed, $feed->token_encrypted];
}

it('serve il calendario con intestazioni, ETag e 304; registra lettura e user agent', function () {
    $fx = bookableTenant();
    reservationIn($fx, ReservationStatus::CONFIRMED);
    [$feed, $token] = feedWithToken($fx);

    expect(strlen($token))->toBe(43)->and($token)->toMatch('/^[A-Za-z0-9_-]{43}$/');

    $response = $this->get("/ical/{$token}.ics", ['User-Agent' => 'Booking.com Calendar Sync']);
    $response->assertOk()
        ->assertHeader('Content-Type', 'text/calendar; charset=utf-8')
        ->assertHeader('ETag');
    expect($response->headers->get('Cache-Control'))->toContain('no-store')
        ->and($response->getContent())->toContain('BEGIN:VCALENDAR')->toContain('DTSTART;VALUE=DATE:20270710')
        ->and($response->headers->getCookies())->toBe([]);

    $this->get("/ical/{$token}.ics", ['If-None-Match' => $response->headers->get('ETag')])->assertStatus(304);

    $stored = inTenant($fx['tenant'], fn () => IcsFeed::query()->findOrFail($feed->id));
    expect($stored->last_fetched_at)->not->toBeNull()
        ->and($stored->last_fetch_user_agent)->toBe('Booking.com Calendar Sync');
});

it('la lettura si registra al massimo ogni 5 minuti', function () {
    $fx = bookableTenant();
    [$feed, $token] = feedWithToken($fx);

    $this->get("/ical/{$token}.ics", ['User-Agent' => 'Primo'])->assertOk();
    $this->travel(2)->minutes();
    $this->get("/ical/{$token}.ics", ['User-Agent' => 'Secondo'])->assertOk();
    $afterTwo = inTenant($fx['tenant'], fn () => IcsFeed::query()->findOrFail($feed->id));

    $this->travel(4)->minutes();
    $this->get("/ical/{$token}.ics", ['User-Agent' => 'Terzo'])->assertOk();
    $afterSix = inTenant($fx['tenant'], fn () => IcsFeed::query()->findOrFail($feed->id));

    expect($afterTwo->last_fetch_user_agent)->toBe('Primo')
        ->and($afterSix->last_fetch_user_agent)->toBe('Terzo');
});

it('token sconosciuto o malformato → 404; il token di un tenant non apre feed di altri', function () {
    $this->get('/ical/'.str_repeat('a', 43).'.ics')->assertNotFound()->assertJsonPath('error.code', 'NOT_FOUND');
    $this->get('/ical/corto.ics')->assertNotFound();

    $fx = bookableTenant();
    [, $token] = feedWithToken($fx);

    expect(DB::selectOne('select app_tenant_by_ics_feed(?) as id', [IcsFeedLifecycle::hashToken($token)])->id)->toBe($fx['tenant'])
        ->and(DB::selectOne('select app_tenant_by_ics_feed(?) as id', [hash('sha256', 'altro')])->id)->toBeNull()
        ->and(DB::table('ics_feeds')->count())->toBe(0); // app_runtime senza contesto: RLS
});

it('limite di 60 richieste all\'ora per token', function () {
    config(['calendar.feed_rate_limit_per_hour' => 3]);
    $fx = bookableTenant();
    [, $token] = feedWithToken($fx);

    foreach (range(1, 3) as $_) {
        $this->get("/ical/{$token}.ics")->assertOk();
    }

    $this->get("/ical/{$token}.ics")->assertStatus(429)->assertJsonPath('error.code', 'RATE_LIMITED');
});

it('API: crea un feed per destinazione accoppiato al calendario dello stesso canale, uno solo ACTIVE', function () {
    $fx = bookableTenant();
    $booking = calendarOn($fx, 'BOOKING');
    signedInOwner($this, $fx['tenant']);

    $created = $this->postJson("/api/v1/admin/units/{$fx['unit']}/ics-feeds", ['destination' => 'BOOKING'])->assertCreated()->json('data');
    $this->postJson("/api/v1/admin/units/{$fx['unit']}/ics-feeds", ['destination' => 'BOOKING'])->assertStatus(409)->assertJsonPath('error.code', 'ICS_FEED_EXISTS');
    $this->postJson("/api/v1/admin/units/{$fx['unit']}/ics-feeds", ['destination' => 'ALTRO'])->assertStatus(400);

    $list = $this->getJson("/api/v1/admin/units/{$fx['unit']}/ics-feeds")->assertOk()->json('data');

    expect($created['paired_external_calendar_id'])->toBe($booking['calendar']->id)
        ->and($created['url'])->toMatch('#/ical/[A-Za-z0-9_-]{43}\.ics$#')
        ->and($created['not_read_warning'])->toBeFalse()
        ->and($list)->toHaveCount(1);

    inTenant($fx['tenant'], fn () => DB::table('ics_feeds')->where('id', $created['id'])->update(['created_at' => now()->subHours(25)]));
    $later = $this->getJson("/api/v1/admin/units/{$fx['unit']}/ics-feeds");
    expect($later->status())->toBe(200, $later->getContent())->and($later->json('data.0.not_read_warning'))->toBeTrue();

    $audit = inTenant($fx['tenant'], fn () => AuditLog::query()->where('action', 'ics_feed.created')->get()->toJson());
    expect($audit)->not->toContain(basename($created['url'], '.ics'));
});

it('API: rotazione → vecchio FROZEN con scadenza a 7 giorni, nuovo ACTIVE; il vecchio URL serve ancora', function () {
    $fx = bookableTenant();
    reservationIn($fx, ReservationStatus::CONFIRMED);
    [$feed, $oldToken] = feedWithToken($fx);
    signedInOwner($this, $fx['tenant']);

    $new = $this->postJson("/api/v1/admin/ics-feeds/{$feed->id}/rotate")->assertCreated()->json('data');
    $this->postJson("/api/v1/admin/ics-feeds/{$feed->id}/rotate")->assertStatus(409);
    $list = collect($this->getJson("/api/v1/admin/units/{$fx['unit']}/ics-feeds")->json('data'))->keyBy('id');

    expect($new['status'])->toBe('ACTIVE')
        ->and($new['url'])->not->toContain($oldToken)
        ->and($list[$feed->id]['status'])->toBe('FROZEN')
        ->and($list[$feed->id]['retires_at'])->not->toBeNull();

    $this->get("/ical/{$oldToken}.ics")->assertOk()->assertSee('DTSTART;VALUE=DATE:20270710', false);
    $this->get('/ical/'.basename($new['url']))->assertOk();
});
