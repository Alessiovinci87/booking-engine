<?php

use App\Domain\Calendar\ConflictService;
use App\Domain\Calendar\Export\FeedContents;
use App\Domain\Calendar\Export\FeedItem;
use App\Domain\Calendar\Export\IcsFeedBuilder;
use App\Domain\Calendar\StateMachines\ExternalEventStateMachine;
use App\Domain\Inventory\InventoryService;
use App\Domain\Inventory\Models\InventoryConflict;
use App\Domain\Inventory\Models\Unit;
use App\Domain\Reservations\Enums\ReservationStatus;
use App\Domain\Reservations\Models\Guest;
use App\Domain\Reservations\StayRange;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Sabre\VObject\Reader;

/*
 * 06 "Matrice dei flussi" (I18, AC13): contenuto esatto del feed per Booking, Airbnb e "altro" con
 * prenotazione diretta, blocco manuale, evento Booking, evento Airbnb, evento "altro", eco confermata,
 * eco sospetta e conflitto aperto. Formato: giornata intera, "Non disponibile", nessun dato
 * dell'ospite; accorpamento degli intervalli identici.
 */

/** @return array<string, string> UID → intervallo, dal documento iCal servito */
function feedEvents(string $ics): array
{
    $events = [];

    foreach (Reader::read($ics)->select('VEVENT') as $event) {
        $events[(string) $event->UID] = $event->DTSTART->getValue().'-'.$event->DTEND->getValue();
    }

    ksort($events);

    return $events;
}

function buildFeed(array $fx, $feed): string
{
    return inTenant($fx['tenant'], fn () => app(IcsFeedBuilder::class)->build($feed->refresh())->body);
}

function matrixFixture(): array
{
    $fx = bookableTenant();
    $reservation = reservationIn($fx, ReservationStatus::CONFIRMED, '[2027-07-10,2027-07-14)');
    $block = inTenant($fx['tenant'], fn () => app(InventoryService::class)->acquireBlock(
        Unit::query()->findOrFail($fx['unit']), StayRange::of('2027-07-20', '2027-07-22'), 'Manutenzione Rossi',
    ));
    $booking = calendarOn($fx, 'BOOKING');
    $airbnb = calendarOn($fx, 'AIRBNB', $booking['http']);
    $other = calendarOn($fx, 'OTHER', $booking['http']);

    $bookingFeed = createFeed($fx['tenant'], $fx['unit'], 'BOOKING', $booking['calendar']->id);
    $airbnbFeed = createFeed($fx['tenant'], $fx['unit'], 'AIRBNB', $airbnb['calendar']->id);
    $otherFeed = createFeed($fx['tenant'], $fx['unit'], 'OTHER', $other['calendar']->id);

    $domain = InventoryService::icsDomain();
    $occ = inTenant($fx['tenant'], fn () => app(InventoryService::class)->occupancyFacts($fx['unit'], $reservation->stay)[0]['ics_uid']);
    syncWith($booking, otaIcs('booking', [
        ['uid' => 'b1@booking.com', 'in' => '2027-08-01', 'out' => '2027-08-04'],
        // eco confermata della prenotazione diretta
        ['uid' => $occ, 'in' => '2027-07-10', 'out' => '2027-07-14'],
    ]));
    syncWith($airbnb, otaIcs('airbnb', [
        ['uid' => 'a1@airbnb.com', 'in' => '2027-08-10', 'out' => '2027-08-12'],
        // sovrapposto alla prenotazione: conflitto aperto (le notti sono già coperte)
        ['uid' => 'a2@airbnb.com', 'in' => '2027-07-13', 'out' => '2027-07-15'],
    ]));
    syncWith($other, otaIcs('booking', [['uid' => 'o1@altro.example', 'in' => '2027-09-01', 'out' => '2027-09-03']]));

    // Eco sospetta: Booking ha letto il nostro feed (con l'evento Airbnb a1) e ce lo rimanda
    inTenant($fx['tenant'], fn () => DB::table('ics_feeds')->where('id', $bookingFeed->id)->update(['last_fetched_at' => CarbonImmutable::now()->addMinute()]));
    test()->travel(2)->minutes();
    syncWith($booking, otaIcs('booking', [
        ['uid' => 'b1@booking.com', 'in' => '2027-08-01', 'out' => '2027-08-04'],
        ['uid' => $occ, 'in' => '2027-07-10', 'out' => '2027-07-14'],
        ['uid' => 'closed-a1@booking.com', 'in' => '2027-08-10', 'out' => '2027-08-12'],
    ]));

    $ev = fn (array $cal, string $uid) => calendarEvents($cal)[$uid]->id;

    return [
        'fx' => $fx, 'feeds' => ['BOOKING' => $bookingFeed, 'AIRBNB' => $airbnbFeed, 'OTHER' => $otherFeed],
        'occ' => $occ,
        'block' => $block->ics_uid,
        'b1' => 'ext-'.$ev($booking, 'b1@booking.com').'@'.$domain,
        'a1' => 'ext-'.$ev($airbnb, 'a1@airbnb.com').'@'.$domain,
        'a2' => 'ext-'.$ev($airbnb, 'a2@airbnb.com').'@'.$domain,
        'o1' => 'ext-'.$ev($other, 'o1@altro.example').'@'.$domain,
        'echo' => 'ext-'.$ev($booking, 'closed-a1@booking.com').'@'.$domain,
        'confirmed_echo' => 'ext-'.$ev($booking, $occ).'@'.$domain,
    ];
}

it('contenuto esatto di ogni feed secondo la matrice dei flussi', function () {
    $m = matrixFixture();
    $booking = feedEvents(buildFeed($m['fx'], $m['feeds']['BOOKING']));
    $airbnb = feedEvents(buildFeed($m['fx'], $m['feeds']['AIRBNB']));
    $other = feedEvents(buildFeed($m['fx'], $m['feeds']['OTHER']));

    $expected = fn (array $keys) => collect($keys)->mapWithKeys(fn ($k) => [$m[$k] => true])->keys()->sort()->values()->all();

    // Booking: diretta, blocco, Airbnb (a1, a2), altro; niente eventi Booking, niente echi
    expect(array_keys($booking))->toBe($expected(['occ', 'block', 'a1', 'a2', 'o1']))
        // Airbnb: diretta, blocco, Booking (b1), altro; niente eventi Airbnb, niente echi
        ->and(array_keys($airbnb))->toBe($expected(['occ', 'block', 'b1', 'o1']))
        // Altro (accoppiato al calendario "altro"): diretta, blocco, Booking, Airbnb; non il proprio
        ->and(array_keys($other))->toBe($expected(['occ', 'block', 'b1', 'a1', 'a2']))
        ->and($booking[$m['occ']])->toBe('20270710-20270714');

    foreach ([$booking, $airbnb, $other] as $feed) {
        expect(array_keys($feed))->not->toContain($m['echo'])->not->toContain($m['confirmed_echo']);
    }
});

it('formato: giornata intera, SUMMARY neutro, nessun dato dell\'ospite o nota del gestore', function () {
    $m = matrixFixture();
    $ics = buildFeed($m['fx'], $m['feeds']['AIRBNB']);
    $guest = inTenant($m['fx']['tenant'], fn () => Guest::query()->firstOrFail());

    expect($ics)->toContain('DTSTART;VALUE=DATE:20270710')
        ->toContain('DTEND;VALUE=DATE:20270714')
        ->toContain('SUMMARY:Non disponibile')
        ->not->toContain($guest->email)
        ->not->toContain($guest->last_name)
        ->not->toContain('Manutenzione')
        ->not->toContain('Reserved')
        ->not->toContain('CLOSED');

    foreach (Reader::read($ics)->select('VEVENT') as $event) {
        expect((string) $event->SUMMARY)->toBe('Non disponibile')
            ->and($event->DESCRIPTION)->toBeNull();
    }
});

it('conflitto aperto senza altre occupazioni sulle date → esportato come cfl-', function () {
    $fx = bookableTenant();
    $booking = calendarOn($fx, 'BOOKING');
    $airbnb = calendarOn($fx, 'AIRBNB', $booking['http']);
    syncWith($booking, otaIcs('booking', [['uid' => 'b1@booking.com', 'in' => '2027-08-01', 'out' => '2027-08-05']]));
    syncWith($airbnb, otaIcs('airbnb', [['uid' => 'a1@airbnb.com', 'in' => '2027-08-03', 'out' => '2027-08-06']]));
    $conflict = inTenant($fx['tenant'], fn () => InventoryConflict::query()->firstOrFail());

    // L'evento Booking viene poi rimosso dal gestore mentre il conflitto resta aperto (preso in carico)
    inTenant($fx['tenant'], function () use ($conflict, $booking) {
        app(ConflictService::class)->investigate($conflict, null);
        app(ExternalEventStateMachine::class)->removeByManager(calendarEvents($booking)['b1@booking.com']);
    });

    $feed = createFeed($fx['tenant'], $fx['unit'], 'AIRBNB', $airbnb['calendar']->id);
    $events = feedEvents(buildFeed($fx, $feed));

    expect($events)->toBe(['cfl-'.$conflict->id.'@'.InventoryService::icsDomain() => '20270803-20270805']);
});

it('accorpamento: intervalli identici → resta la precedenza più alta', function () {
    $stay = StayRange::of('2027-07-10', '2027-07-14');
    $now = CarbonImmutable::now();
    $items = [
        new FeedItem(FeedItem::CONFLICT, 'c', 'cfl-c@x', $stay, $now, $now),
        new FeedItem(FeedItem::EXTERNAL_EVENT, 'e', 'ext-e@x', $stay, $now, $now),
        new FeedItem(FeedItem::RESERVATION, 'r', 'occ-r@x', $stay, $now, $now),
        new FeedItem(FeedItem::MANUAL_BLOCK, 'b', 'occ-b@x', $stay, $now, $now),
        new FeedItem(FeedItem::EXTERNAL_EVENT, 'f', 'ext-f@x', StayRange::of('2027-07-10', '2027-07-15'), $now, $now),
    ];

    $merged = FeedContents::merge($items);

    expect(array_map(fn ($i) => $i->uid, $merged))->toBe(['occ-r@x', 'ext-f@x']);
});
