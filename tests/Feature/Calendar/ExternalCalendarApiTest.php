<?php

use App\Domain\Calendar\Enums\ExternalEventStatus;
use App\Domain\Calendar\Http\FetchError;
use App\Domain\Calendar\Http\FetchFailed;
use App\Domain\Calendar\IcalImportService;
use App\Domain\Calendar\Models\ExternalCalendar;
use App\Domain\Inventory\Enums\BookingMode;
use App\Domain\Inventory\Models\Unit;
use App\Domain\Reservations\Enums\ReservationStatus;

/*
 * 5.6 — Calendari importati dal pannello (07 "Calendario", 06): URL controllato, prima sincronizzazione
 * obbligatoria, I12 con conferma esplicita, cambio di URL che conserva gli eventi, disattivazione,
 * rimozione, "sincronizza ora" limitato, storico.
 */

function apiCalendarSetup($test, string $mode = 'REQUEST'): array
{
    $fx = bookableTenant($mode);
    $http = fakeCalendarHttp();
    signedInOwner($test, $fx['tenant']);

    return [$fx, $http];
}

it('crea un calendario con prima sincronizzazione riuscita; dell\'URL solo l\'host', function () {
    [$fx, $http] = apiCalendarSetup($this);
    $url = 'https://ical.example.com/export/segreto-123.ics';
    $http->responses[$url] = icsResponse(otaIcs('booking', [['uid' => 'b1@booking.com', 'in' => '2027-08-01', 'out' => '2027-08-03']]));

    $created = $this->postJson("/api/v1/admin/units/{$fx['unit']}/external-calendars", ['source' => 'BOOKING', 'url' => $url])
        ->assertCreated()->json('data');

    expect($created['url_host'])->toBe('ical.example.com')
        ->and(json_encode($created))->not->toContain('segreto')
        ->and($created['first_sync']['outcome'])->toBe('SUCCESS')
        ->and($created['active_events'])->toBe(1)
        ->and($created['health'])->toBe('ACTIVE');

    $list = $this->getJson("/api/v1/admin/units/{$fx['unit']}/external-calendars")->assertOk()->json('data');
    expect($list)->toHaveCount(1)->and($list[0]['active_events'])->toBe(1)
        ->and(json_encode($list))->not->toContain('segreto');
});

it('prima sincronizzazione fallita → 422 INVALID_ICAL_URL e nessun calendario salvato', function () {
    [$fx, $http] = apiCalendarSetup($this);
    $http->responses['https://ical.example.com/x.ics'] = new FetchFailed(FetchError::TIMEOUT);

    $this->postJson("/api/v1/admin/units/{$fx['unit']}/external-calendars", ['source' => 'AIRBNB', 'url' => 'https://ical.example.com/x.ics'])
        ->assertStatus(422)->assertJsonPath('error.code', 'INVALID_ICAL_URL')->assertJsonPath('error.details.reason', 'TIMEOUT');
    $this->postJson("/api/v1/admin/units/{$fx['unit']}/external-calendars", ['source' => 'AIRBNB', 'url' => 'http://ical.example.com/x.ics'])
        ->assertStatus(422)->assertJsonPath('error.details.reason', 'SCHEME_NOT_ALLOWED');
    $this->postJson("/api/v1/admin/units/{$fx['unit']}/external-calendars", ['source' => 'AIRBNB', 'url' => 'https://internal.example.com/x.ics'])
        ->assertStatus(422)->assertJsonPath('error.details.reason', 'BLOCKED_ADDRESS');

    expect(inTenant($fx['tenant'], fn () => ExternalCalendar::query()->count()))->toBe(0);
});

it('unità INSTANT: 409 UNIT_IS_INSTANT senza conferma; con conferma passa a REQUEST (I12)', function () {
    [$fx, $http] = apiCalendarSetup($this, 'INSTANT');
    $url = 'https://ical.example.com/a.ics';
    $http->responses[$url] = icsResponse(otaIcs('airbnb', []));

    $this->postJson("/api/v1/admin/units/{$fx['unit']}/external-calendars", ['source' => 'AIRBNB', 'url' => $url])
        ->assertStatus(409)->assertJsonPath('error.code', 'UNIT_IS_INSTANT');
    $this->postJson("/api/v1/admin/units/{$fx['unit']}/external-calendars", ['source' => 'AIRBNB', 'url' => $url, 'switch_to_request' => true])
        ->assertCreated();

    expect(inTenant($fx['tenant'], fn () => Unit::query()->findOrFail($fx['unit'])->booking_mode))->toBe(BookingMode::REQUEST);
});

it('cambio di URL: gli eventi del vecchio URL restano finché il nuovo non li conferma o il gestore li rimuove', function () {
    [$fx, $http] = apiCalendarSetup($this);
    $old = 'https://ical.example.com/old.ics';
    $new = 'https://ical.example.com/new.ics';
    $http->responses[$old] = icsResponse(otaIcs('booking', [
        ['uid' => 'b1@booking.com', 'in' => '2027-08-01', 'out' => '2027-08-03'],
        ['uid' => 'b2@booking.com', 'in' => '2027-08-10', 'out' => '2027-08-12'],
    ]));
    $id = $this->postJson("/api/v1/admin/units/{$fx['unit']}/external-calendars", ['source' => 'BOOKING', 'url' => $old])->json('data.id');
    $http->responses[$new] = icsResponse(otaIcs('booking', [['uid' => 'b1@booking.com', 'in' => '2027-08-01', 'out' => '2027-08-03']]));

    $this->travel(1)->minutes();
    $this->patchJson("/api/v1/admin/external-calendars/{$id}", ['url' => $new])->assertOk()->assertJsonPath('data.last_run.outcome', 'SUCCESS');
    $this->travel(15)->minutes();
    app(IcalImportService::class)->sync($fx['tenant'], $id);

    $cal = ['tenant' => $fx['tenant'], 'calendar' => inTenant($fx['tenant'], fn () => ExternalCalendar::query()->findOrFail($id))];
    $events = calendarEvents($cal);
    expect($events['b2@booking.com']->status)->toBe(ExternalEventStatus::ACTIVE)
        ->and($events['b1@booking.com']->status)->toBe(ExternalEventStatus::ACTIVE);

    $this->postJson("/api/v1/admin/external-events/{$events['b2@booking.com']->id}/remove")->assertOk()->assertJsonPath('data.status', 'REMOVED');
});

it('disattiva, riattiva, rimuove (eventi rimossi su richiesta del gestore) e storico', function () {
    [$fx, $http] = apiCalendarSetup($this);
    $url = 'https://ical.example.com/c.ics';
    $http->responses[$url] = icsResponse(otaIcs('booking', [['uid' => 'b1@booking.com', 'in' => '2027-08-01', 'out' => '2027-08-03']]));
    $id = $this->postJson("/api/v1/admin/units/{$fx['unit']}/external-calendars", ['source' => 'BOOKING', 'url' => $url])->json('data.id');

    $this->patchJson("/api/v1/admin/external-calendars/{$id}", ['status' => 'DISABLED'])->assertOk()->assertJsonPath('data.status', 'DISABLED');
    expect(inTenant($fx['tenant'], fn () => Unit::query()->findOrFail($fx['unit'])->sync_health->value))->toBe('NONE');
    $this->patchJson("/api/v1/admin/external-calendars/{$id}", ['status' => 'ACTIVE'])->assertOk()->assertJsonPath('data.status', 'ACTIVE');

    $this->getJson("/api/v1/admin/external-calendars/{$id}/runs")->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.outcome', 'SUCCESS');

    $this->deleteJson("/api/v1/admin/external-calendars/{$id}")->assertOk()->assertJsonPath('data.events_removed', 1)->assertJsonPath('data.status', 'DISABLED');
});

it('sincronizza ora: al massimo una volta ogni 2 minuti', function () {
    [$fx, $http] = apiCalendarSetup($this);
    $url = 'https://ical.example.com/s.ics';
    $http->responses[$url] = icsResponse(otaIcs('booking', []));
    $id = $this->postJson("/api/v1/admin/units/{$fx['unit']}/external-calendars", ['source' => 'BOOKING', 'url' => $url])->json('data.id');

    $this->postJson("/api/v1/admin/external-calendars/{$id}/sync-now")->assertOk()->assertJsonPath('data.last_run.outcome', 'SUCCESS');
    $this->postJson("/api/v1/admin/external-calendars/{$id}/sync-now")->assertStatus(429)->assertJsonPath('error.code', 'RATE_LIMITED');
    $this->travel(121)->seconds();
    $this->postJson("/api/v1/admin/external-calendars/{$id}/sync-now")->assertOk();
});

it('calendario di un altro tenant → 404', function () {
    $other = bookableTenant();
    $cal = calendarOn($other);
    apiCalendarSetup($this);

    $this->getJson("/api/v1/admin/external-calendars/{$cal['calendar']->id}/runs")->assertNotFound();
    $this->postJson("/api/v1/admin/external-calendars/{$cal['calendar']->id}/sync-now")->assertNotFound();
    $this->deleteJson("/api/v1/admin/external-calendars/{$cal['calendar']->id}")->assertNotFound();
});

it('la prima sincronizzazione apre i conflitti con le prenotazioni esistenti', function () {
    [$fx, $http] = apiCalendarSetup($this, 'INSTANT');
    reservationIn($fx, ReservationStatus::CONFIRMED, '[2027-07-10,2027-07-14)');
    $url = 'https://ical.example.com/k.ics';
    $http->responses[$url] = icsResponse(otaIcs('booking', [['uid' => 'b1@booking.com', 'in' => '2027-07-12', 'out' => '2027-07-13']]));

    $this->postJson("/api/v1/admin/units/{$fx['unit']}/external-calendars", ['source' => 'BOOKING', 'url' => $url, 'switch_to_request' => true])->assertCreated();

    expect($this->getJson('/api/v1/admin/conflicts')->json('data'))->toHaveCount(1);
});
