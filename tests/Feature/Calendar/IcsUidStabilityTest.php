<?php

use App\Domain\Calendar\Export\IcsFeedBuilder;
use App\Domain\Inventory\InventoryService;
use App\Domain\Payments\Gateway\Fake\FakeCustomerAction;
use App\Domain\Pricing\Calculator\PriceRequest;
use App\Domain\Pricing\PriceQuoteService;
use App\Domain\Reservations\CancellationRefund;
use App\Domain\Reservations\Enums\ReservationStatus;
use App\Domain\Reservations\StayRange;
use Sabre\VObject\Reader;

/*
 * 06 "Un'occupazione logica, un UID" (AC2): l'UID `occ-{id}@{dominio}` non cambia dalla fase
 * temporanea alla conferma; un cambio di date incrementa SEQUENCE; al rilascio l'evento scompare.
 * Lo stesso per un evento importato riesportato (`ext-{id}`).
 */

/** @return array<string, array{range: string, sequence: int}> */
function servedEvents(array $fx, $feed): array
{
    $ics = inTenant($fx['tenant'], fn () => app(IcsFeedBuilder::class)->build($feed->refresh())->body);
    $events = [];

    foreach (Reader::read($ics)->select('VEVENT') as $e) {
        $events[(string) $e->UID] = ['range' => $e->DTSTART->getValue().'-'.$e->DTEND->getValue(), 'sequence' => (int) (string) $e->SEQUENCE];
    }

    return $events;
}

it('UID stabile dal checkout alla conferma, SEQUENCE alla modifica, scomparsa alla cancellazione', function () {
    $fx = bookableTenant();
    $feeds = [createFeed($fx['tenant'], $fx['unit'], 'BOOKING'), createFeed($fx['tenant'], $fx['unit'], 'AIRBNB'), createFeed($fx['tenant'], $fx['unit'], 'OTHER')];

    $pending = startCheckout($fx, '[2027-07-10,2027-07-14)');
    $atCheckout = array_map(fn ($f) => servedEvents($fx, $f), $feeds);
    $uid = array_key_first($atCheckout[0]);

    expect($uid)->toStartWith('occ-')->toEndWith('@'.InventoryService::icsDomain());

    foreach ($atCheckout as $events) {
        expect($events)->toBe([$uid => ['range' => '20270710-20270714', 'sequence' => 0]]);
    }

    $confirmed = simulateCustomer($fx, $pending, FakeCustomerAction::PAY);
    expect($confirmed->status)->toBe(ReservationStatus::CONFIRMED)
        ->and(servedEvents($fx, $feeds[1]))->toBe([$uid => ['range' => '20270710-20270714', 'sequence' => 0]]);

    inTenant($fx['tenant'], function () use ($confirmed) {
        $stay = StayRange::of('2027-07-11', '2027-07-16');
        $price = app(PriceQuoteService::class)->quote($confirmed->unit, new PriceRequest($stay, 2, 0, 0, false));
        machine()->modify($confirmed, $stay, 2, 0, $price, null, null);
    });

    foreach ($feeds as $feed) {
        expect(servedEvents($fx, $feed))->toBe([$uid => ['range' => '20270711-20270716', 'sequence' => 1]]);
    }

    $modified = reloadReservation($fx, $confirmed->id);
    inTenant($fx['tenant'], fn () => machine()->cancel($modified, CancellationRefund::for($modified, null), null, null));

    foreach ($feeds as $feed) {
        expect(servedEvents($fx, $feed))->toBe([]);
    }
});

it('evento importato riesportato: ext-{id} stabile, SEQUENCE quando l\'OTA cambia le date', function () {
    $fx = bookableTenant();
    $booking = calendarOn($fx, 'BOOKING');
    $airbnbFeed = createFeed($fx['tenant'], $fx['unit'], 'AIRBNB');

    syncWith($booking, otaIcs('booking', [['uid' => 'b1@booking.com', 'in' => '2027-08-01', 'out' => '2027-08-04']]));
    $first = servedEvents($fx, $airbnbFeed);

    $this->travel(11)->minutes();
    syncWith($booking, otaIcs('booking', [['uid' => 'b1@booking.com', 'in' => '2027-08-02', 'out' => '2027-08-06']]));
    $second = servedEvents($fx, $airbnbFeed);

    expect(array_keys($second))->toBe(array_keys($first))
        ->and(array_values($first)[0])->toBe(['range' => '20270801-20270804', 'sequence' => 0])
        ->and(array_values($second)[0])->toBe(['range' => '20270802-20270806', 'sequence' => 1]);
});

it('stesso contenuto → stesso documento ed ETag', function () {
    $fx = bookableTenant();
    reservationIn($fx, ReservationStatus::CONFIRMED);
    $feed = createFeed($fx['tenant'], $fx['unit'], 'BOOKING');

    $a = inTenant($fx['tenant'], fn () => app(IcsFeedBuilder::class)->build($feed));
    $this->travel(5)->minutes();
    $b = inTenant($fx['tenant'], fn () => app(IcsFeedBuilder::class)->build($feed));

    expect($a->etag)->toBe($b->etag)->and($a->body)->toBe($b->body);
});
