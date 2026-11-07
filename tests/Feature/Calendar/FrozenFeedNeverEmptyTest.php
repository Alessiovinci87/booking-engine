<?php

use App\Domain\Calendar\Enums\ChannelSource;
use App\Domain\Calendar\IcsFeedLifecycle;
use App\Domain\Calendar\Models\IcsFeed;
use App\Domain\Calendar\StateMachines\IcsFeedStateMachine;
use App\Domain\Inventory\Models\Unit;
use App\Domain\Reservations\CancellationRefund;
use App\Domain\Reservations\Enums\ReservationStatus;

/*
 * I19 (06 "Ciclo di vita dei feed"): un feed FROZEN serve l'ultima versione congelata, aggiornata solo
 * con le prenotazioni già esistenti (modifiche e cancellazioni incluse), senza nuove vendite; non serve
 * mai un calendario vuoto.
 */

function frozenFeed(array $fx): array
{
    $token = IcsFeedLifecycle::newToken();
    $feed = inTenant($fx['tenant'], function () use ($fx, $token) {
        $machine = app(IcsFeedStateMachine::class);
        $feed = $machine->create($fx['unit'], ChannelSource::BOOKING, null, $token, IcsFeedLifecycle::hashToken($token));
        $machine->freeze($feed, 'offboarding');

        return $feed;
    });

    return [$feed, $token];
}

it('FROZEN mostra le prenotazioni esistenti al congelamento, non quelle nuove', function () {
    $fx = bookableTenant();
    reservationIn($fx, ReservationStatus::CONFIRMED, '[2027-07-10,2027-07-14)');
    [, $token] = frozenFeed($fx);

    $this->travel(1)->minutes();
    reservationIn($fx, ReservationStatus::CONFIRMED, '[2027-07-20,2027-07-24)');

    $ics = $this->get("/ical/{$token}.ics")->assertOk()->getContent();

    expect($ics)->toContain('DTSTART;VALUE=DATE:20270710')->not->toContain('DTSTART;VALUE=DATE:20270720');
});

it('FROZEN senza più elementi → 503 con Retry-After, mai un calendario vuoto', function () {
    $fx = bookableTenant();
    $reservation = reservationIn($fx, ReservationStatus::CONFIRMED, '[2027-07-10,2027-07-14)');
    [, $token] = frozenFeed($fx);

    $this->get("/ical/{$token}.ics")->assertOk();

    inTenant($fx['tenant'], fn () => machine()->cancel($reservation, CancellationRefund::for($reservation, null), null, null));

    $response = $this->get("/ical/{$token}.ics");
    $response->assertStatus(503)->assertJsonPath('error.code', 'FEED_UNAVAILABLE')->assertHeader('Retry-After', '3600');
    expect($response->getContent())->not->toContain('BEGIN:VCALENDAR');
});

it('FROZEN creato senza alcun elemento → 503 fin dalla prima lettura', function () {
    $fx = bookableTenant();
    [, $token] = frozenFeed($fx);

    $this->get("/ical/{$token}.ics")->assertStatus(503);
});

it('un feed ACTIVE vuoto è legittimo (nessuna occupazione)', function () {
    $fx = bookableTenant();
    $feed = inTenant($fx['tenant'], fn () => app(IcsFeedLifecycle::class)->create(Unit::query()->findOrFail($fx['unit']), ChannelSource::AIRBNB));

    $this->get("/ical/{$feed->token_encrypted}.ics")->assertOk()->assertSee('BEGIN:VCALENDAR', false);
    expect(inTenant($fx['tenant'], fn () => IcsFeed::query()->count()))->toBe(1);
});
