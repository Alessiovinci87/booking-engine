<?php

use App\Domain\Calendar\ConflictService;
use App\Domain\Calendar\Enums\ChannelSource;
use App\Domain\Calendar\Enums\EchoKind;
use App\Domain\Calendar\Export\FeedContents;
use App\Domain\Inventory\Models\InventoryConflict;
use App\Domain\Reservations\Enums\ReservationStatus;
use App\Domain\Reservations\Exceptions\IllegalTransition;
use Carbon\CarbonImmutable;

/*
 * 06 "Classificazione degli echi" e test obbligatorio "Ciclo eco": evento Airbnb → feed per Booking →
 * ritorno da Booking con UID estraneo → SUSPECTED_ECHO, nessun conflitto con l'evento originale, non
 * riesportato verso Airbnb. Riclassificazione dal pannello.
 */

function uidsIn(array $fx, ChannelSource $destination, ?string $paired): array
{
    return inTenant($fx['tenant'], fn () => array_map(
        fn ($i) => $i->uid,
        app(FeedContents::class)->forDestination($fx['unit'], $destination, $paired),
    ));
}

function echoLoop(): array
{
    $fx = bookableTenant();
    $booking = calendarOn($fx, 'BOOKING');
    $airbnb = calendarOn($fx, 'AIRBNB', $booking['http']);

    // 1. prenotazione su Airbnb, importata da noi
    syncWith($airbnb, otaIcs('airbnb', [['uid' => 'a1@airbnb.com', 'in' => '2027-08-01', 'out' => '2027-08-05']]));
    $airbnbEvent = calendarEvents($airbnb)['a1@airbnb.com'];

    // 2. Booking legge il nostro feed per Booking, che contiene l'evento Airbnb
    test()->travel(1)->minutes();
    createFeed($fx['tenant'], $fx['unit'], 'BOOKING', $booking['calendar']->id, CarbonImmutable::now());
    createFeed($fx['tenant'], $fx['unit'], 'AIRBNB', $airbnb['calendar']->id, CarbonImmutable::now());

    // 3. Booking ce lo rimanda come blocco con un UID suo
    test()->travel(5)->minutes();
    syncWith($booking, otaIcs('booking', [['uid' => 'closed-123@booking.com', 'in' => '2027-08-01', 'out' => '2027-08-05']]));

    return [$fx, $booking, $airbnb, $airbnbEvent];
}

it('l\'evento Airbnb compare nel feed per Booking e non in quello per Airbnb', function () {
    [$fx, $booking, $airbnb, $airbnbEvent] = echoLoop();
    $ext = 'ext-'.$airbnbEvent->id.'@';

    expect(implode(',', uidsIn($fx, ChannelSource::BOOKING, $booking['calendar']->id)))->toContain($ext)
        ->and(implode(',', uidsIn($fx, ChannelSource::AIRBNB, $airbnb['calendar']->id)))->not->toContain($ext);
});

it('il ritorno da Booking con UID estraneo è SUSPECTED_ECHO, non genera conflitti e non va verso Airbnb', function () {
    [$fx, $booking, $airbnb] = echoLoop();
    $echo = calendarEvents($booking)['closed-123@booking.com'];

    expect($echo->echo_kind)->toBe(EchoKind::SUSPECTED_ECHO)
        ->and($echo->echo_of_ics_uid)->toBeNull()
        ->and(inTenant($fx['tenant'], fn () => InventoryConflict::query()->count()))->toBe(0)
        ->and(implode(',', uidsIn($fx, ChannelSource::AIRBNB, $airbnb['calendar']->id)))->not->toContain('ext-'.$echo->id)
        ->and(implode(',', uidsIn($fx, ChannelSource::OTHER, null)))->not->toContain('ext-'.$echo->id);
});

it('stesso intervallo ma prima che l\'OTA abbia letto il feed → evento normale', function () {
    $fx = bookableTenant();
    $booking = calendarOn($fx, 'BOOKING');
    $airbnb = calendarOn($fx, 'AIRBNB', $booking['http']);
    createFeed($fx['tenant'], $fx['unit'], 'BOOKING', $booking['calendar']->id, CarbonImmutable::now()->subHour());

    syncWith($airbnb, otaIcs('airbnb', [['uid' => 'a1@airbnb.com', 'in' => '2027-08-01', 'out' => '2027-08-05']]));
    syncWith($booking, otaIcs('booking', [['uid' => 'b9@booking.com', 'in' => '2027-08-01', 'out' => '2027-08-05']]));

    expect(calendarEvents($booking)['b9@booking.com']->echo_kind)->toBe(EchoKind::NONE)
        ->and(inTenant($fx['tenant'], fn () => InventoryConflict::query()->count()))->toBe(1);
});

it('eco sospetta che tocca anche un\'altra occupazione → conflitto con suspected_echo', function () {
    $fx = bookableTenant();
    $booking = calendarOn($fx, 'BOOKING');
    $airbnb = calendarOn($fx, 'AIRBNB', $booking['http']);
    syncWith($airbnb, otaIcs('airbnb', [['uid' => 'a1@airbnb.com', 'in' => '2027-07-01', 'out' => '2027-07-12']]));
    // una prenotazione diretta successiva non può sovrapporsi all'evento: si crea dopo, su date adiacenti
    reservationIn($fx, ReservationStatus::CONFIRMED, '[2027-07-12,2027-07-16)');
    $this->travel(1)->minutes();
    createFeed($fx['tenant'], $fx['unit'], 'BOOKING', $booking['calendar']->id, CarbonImmutable::now());
    $this->travel(1)->minutes();

    // Booking rimanda la prenotazione diretta con UID estraneo: è l'eco della prenotazione, nessun conflitto
    syncWith($booking, otaIcs('booking', [['uid' => 'x1@booking.com', 'in' => '2027-07-12', 'out' => '2027-07-16']]));
    expect(calendarEvents($booking)['x1@booking.com']->echo_kind)->toBe(EchoKind::SUSPECTED_ECHO)
        ->and(inTenant($fx['tenant'], fn () => InventoryConflict::query()->count()))->toBe(0);
});

it('"non è un\'eco": torna NONE, viene riesportata e partecipa ai conflitti', function () {
    [$fx, $booking, $airbnb] = echoLoop();
    $echo = calendarEvents($booking)['closed-123@booking.com'];
    signedInOwner($this, $fx['tenant']);

    $response = $this->postJson("/api/v1/admin/external-events/{$echo->id}/not-echo")->assertOk();

    expect($response->json('data.echo_kind'))->toBe('NONE')
        ->and($response->json('data.conflicts'))->toHaveCount(1)
        ->and(implode(',', uidsIn($fx, ChannelSource::AIRBNB, $airbnb['calendar']->id)))->toContain('ext-'.$echo->id);

    $this->postJson("/api/v1/admin/external-events/{$echo->id}/not-echo")->assertStatus(409);
    expect(fn () => inTenant($fx['tenant'], fn () => app(ConflictService::class)->notAnEcho(calendarEvents($booking)['closed-123@booking.com'])))
        ->toThrow(IllegalTransition::class);
});
