<?php

use App\Domain\Inventory\Enums\ConflictStatus;
use App\Domain\Inventory\InventoryService;
use App\Domain\Inventory\Models\InventoryConflict;
use App\Domain\Reservations\Enums\ReservationStatus;
use App\Domain\Tenancy\Exceptions\BusinessRuleViolation;
use App\Domain\Tenancy\Models\OutboxMessage;

/*
 * 06 "Conflitti" (AC7): un evento importato che si sovrappone a una prenotazione confermata o a un
 * evento di un altro calendario apre un conflitto OPEN sulle notti in comune e notifica il gestore.
 * Nessuna cancellazione automatica; nessun doppione; un'eco confermata non genera conflitti.
 */

function conflictsOf(string $tenant): array
{
    return inTenant($tenant, fn () => InventoryConflict::query()->orderBy('detected_at')->get()->all());
}

it('evento Booking sulle date di una prenotazione CONFIRMED → conflitto OPEN, prenotazione intatta, notifica', function () {
    $fx = bookableTenant();
    $reservation = reservationIn($fx, ReservationStatus::CONFIRMED, '[2027-07-10,2027-07-14)');
    $cal = calendarOn($fx);

    syncWith($cal, otaIcs('booking', [['uid' => 'b1@booking.com', 'in' => '2027-07-12', 'out' => '2027-07-16']]));

    $conflicts = conflictsOf($fx['tenant']);
    $outbox = inTenant($fx['tenant'], fn () => OutboxMessage::query()->where('type', 'calendar.conflict_opened')->get());

    expect($conflicts)->toHaveCount(1)
        ->and($conflicts[0]->status)->toBe(ConflictStatus::OPEN)
        ->and((string) $conflicts[0]->stay)->toBe('[2027-07-12,2027-07-14)')
        ->and($conflicts[0]->occupancy_id)->not->toBeNull()
        ->and($conflicts[0]->suspected_echo)->toBeFalse()
        ->and(reloadReservation($fx, $reservation->id)->status)->toBe(ReservationStatus::CONFIRMED)
        ->and(occupancyViolations($fx['tenant']))->toBe([])
        ->and($outbox)->toHaveCount(1)
        ->and($outbox[0]->payload)->toBe(['conflict_id' => $conflicts[0]->id]);

    // Nuovo giro con lo stesso evento: nessun doppione
    $this->travel(11)->minutes();
    syncWith($cal, otaIcs('booking', [['uid' => 'b1@booking.com', 'in' => '2027-07-12', 'out' => '2027-07-16']]));
    expect(conflictsOf($fx['tenant']))->toHaveCount(1);
});

it('evento Airbnb sovrapposto a un evento Booking → conflitto tra i due eventi', function () {
    $fx = bookableTenant();
    $booking = calendarOn($fx, 'BOOKING');
    $airbnb = calendarOn($fx, 'AIRBNB', $booking['http']);

    syncWith($booking, otaIcs('booking', [['uid' => 'b1@booking.com', 'in' => '2027-08-01', 'out' => '2027-08-05']]));
    syncWith($airbnb, otaIcs('airbnb', [['uid' => 'a1@airbnb.com', 'in' => '2027-08-04', 'out' => '2027-08-07']]));

    $conflicts = conflictsOf($fx['tenant']);
    $events = array_merge(calendarEvents($booking), calendarEvents($airbnb));

    expect($conflicts)->toHaveCount(1)
        ->and((string) $conflicts[0]->stay)->toBe('[2027-08-04,2027-08-05)')
        ->and($conflicts[0]->external_event_id)->toBe($events['a1@airbnb.com']->id)
        ->and($conflicts[0]->other_external_event_id)->toBe($events['b1@booking.com']->id)
        ->and($conflicts[0]->occupancy_id)->toBeNull();
});

it('eco confermata (nostro UID) sulle date della prenotazione → nessun conflitto', function () {
    $fx = bookableTenant();
    $reservation = reservationIn($fx, ReservationStatus::CONFIRMED, '[2027-07-10,2027-07-14)');
    $cal = calendarOn($fx);
    $uid = inTenant($fx['tenant'], fn () => app(InventoryService::class)->occupancyFacts($fx['unit'], $reservation->stay)[0]['ics_uid']);

    syncWith($cal, otaIcs('booking', [['uid' => $uid, 'in' => '2027-07-10', 'out' => '2027-07-14']]));

    expect(conflictsOf($fx['tenant']))->toBe([]);
});

it('la data cambiata di un evento genera il conflitto con la prenotazione che ora tocca', function () {
    $fx = bookableTenant();
    reservationIn($fx, ReservationStatus::CONFIRMED, '[2027-07-10,2027-07-14)');
    $cal = calendarOn($fx);

    syncWith($cal, otaIcs('booking', [['uid' => 'b1@booking.com', 'in' => '2027-07-20', 'out' => '2027-07-22']]));
    expect(conflictsOf($fx['tenant']))->toBe([]);

    syncWith($cal, otaIcs('booking', [['uid' => 'b1@booking.com', 'in' => '2027-07-13', 'out' => '2027-07-15']]));
    expect(conflictsOf($fx['tenant']))->toHaveCount(1);
});

it('approvazione bloccata con 409 CONFLICT_OPEN se un evento importato tocca la richiesta', function () {
    $fx = bookableTenant('REQUEST');
    $reservation = reservationIn($fx, ReservationStatus::AWAITING_APPROVAL, '[2027-07-10,2027-07-14)');
    $cal = calendarOn($fx);
    syncWith($cal, otaIcs('booking', [['uid' => 'b1@booking.com', 'in' => '2027-07-11', 'out' => '2027-07-12']]));

    expect(fn () => inTenant($fx['tenant'], fn () => machine()->approve($reservation)))
        ->toThrow(fn (BusinessRuleViolation $e) => expect($e->errorCode)->toBe('CONFLICT_OPEN'));
});
