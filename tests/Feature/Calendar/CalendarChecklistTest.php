<?php

use App\Domain\Tenancy\Models\AuditLog;
use Carbon\CarbonImmutable;

/*
 * 06 "Topologia richiesta: a stella" (5.6): checklist di collegamento con passi automatici e conferme
 * esplicite del gestore, per ogni canale usato dall'unità.
 */

it('passi per Booking e Airbnb, conferme del gestore, checklist completa', function () {
    $fx = bookableTenant();
    $booking = calendarOn($fx, 'BOOKING');
    $airbnb = calendarOn($fx, 'AIRBNB', $booking['http']);
    syncWith($booking, otaIcs('booking', []));
    signedInOwner($this, $fx['tenant']);

    $this->postJson("/api/v1/admin/units/{$fx['unit']}/ics-feeds", ['destination' => 'BOOKING'])->assertCreated();
    $this->postJson("/api/v1/admin/units/{$fx['unit']}/ics-feeds", ['destination' => 'AIRBNB'])->assertCreated();

    $steps = collect($this->getJson("/api/v1/admin/units/{$fx['unit']}/calendar-checklist")->assertOk()->json('data.steps'))->keyBy('key');

    expect($steps->keys()->all())->toBe([
        'BOOKING.feed_created', 'BOOKING.feed_linked', 'BOOKING.calendar_imported',
        'AIRBNB.feed_created', 'AIRBNB.feed_linked', 'AIRBNB.calendar_imported',
        'direct_links_removed',
    ])
        ->and($steps['BOOKING.feed_created']['done'])->toBeTrue()
        ->and($steps['BOOKING.calendar_imported']['done'])->toBeTrue()
        ->and($steps['AIRBNB.calendar_imported']['done'])->toBeFalse()
        ->and($steps['BOOKING.feed_linked']['kind'])->toBe('manual')
        ->and($steps['BOOKING.feed_linked']['done'])->toBeFalse();

    $this->postJson("/api/v1/admin/units/{$fx['unit']}/calendar-checklist/confirm", ['step' => 'BOOKING.feed_created'])
        ->assertStatus(422)->assertJsonPath('error.code', 'CHECKLIST_STEP_INVALID');
    $this->postJson("/api/v1/admin/units/{$fx['unit']}/calendar-checklist/confirm", ['step' => 'BOOKING.feed_linked'])->assertOk();
    $this->postJson("/api/v1/admin/units/{$fx['unit']}/calendar-checklist/confirm", ['step' => 'AIRBNB.feed_linked'])->assertOk();
    $done = $this->postJson("/api/v1/admin/units/{$fx['unit']}/calendar-checklist/confirm", ['step' => 'direct_links_removed'])->assertOk()->json('data');

    expect($done['complete'])->toBeFalse();

    syncWith($airbnb, otaIcs('airbnb', []));
    $final = $this->getJson("/api/v1/admin/units/{$fx['unit']}/calendar-checklist")->json('data');

    expect($final['complete'])->toBeTrue()
        ->and(inTenant($fx['tenant'], fn () => AuditLog::query()->where('action', 'unit.calendar_checklist_confirmed')->count()))->toBe(3);
});

it('un solo canale: nessun passo sui collegamenti diretti tra OTA', function () {
    $fx = bookableTenant();
    calendarOn($fx, 'AIRBNB');
    signedInOwner($this, $fx['tenant']);

    $keys = array_column($this->getJson("/api/v1/admin/units/{$fx['unit']}/calendar-checklist")->json('data.steps'), 'key');

    expect($keys)->toBe(['AIRBNB.feed_created', 'AIRBNB.feed_linked', 'AIRBNB.calendar_imported']);
});

it('l\'ultima lettura dell\'OTA compare come indizio del collegamento', function () {
    $fx = bookableTenant();
    signedInOwner($this, $fx['tenant']);
    createFeed($fx['tenant'], $fx['unit'], 'BOOKING', null, CarbonImmutable::parse('2026-11-01T10:00:00Z'));

    $steps = collect($this->getJson("/api/v1/admin/units/{$fx['unit']}/calendar-checklist")->json('data.steps'))->keyBy('key');

    expect($steps['BOOKING.feed_linked']['evidence']['last_fetched_at'])->toBe('2026-11-01T10:00:00Z');
});
