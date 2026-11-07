<?php

use App\Domain\Calendar\ConflictService;
use App\Domain\Inventory\Enums\ConflictResolution;
use App\Domain\Inventory\Enums\ConflictStatus;
use App\Domain\Inventory\InventoryService;
use App\Domain\Inventory\Models\InventoryConflict;
use App\Domain\Reservations\Enums\ReservationStatus;
use App\Domain\Reservations\StayRange;
use App\Domain\Tenancy\Models\AuditLog;
use App\Domain\Tenancy\Models\OutboxMessage;

/*
 * I13 (03 sezione 5, 06): la chiusura di un conflitto toglie solo il blocco del conflitto; le notti
 * occupate da prenotazioni o eventi esterni restano non vendibili. Risoluzione automatica quando
 * l'evento esterno viene rimosso con conferma. API del pannello.
 */

function openConflict(): array
{
    $fx = bookableTenant();
    $reservation = reservationIn($fx, ReservationStatus::CONFIRMED, '[2027-07-10,2027-07-14)');
    $cal = calendarOn($fx);
    syncWith($cal, otaIcs('booking', [
        ['uid' => 'b1@booking.com', 'in' => '2027-07-12', 'out' => '2027-07-16'],
        ['uid' => 'b2@booking.com', 'in' => '2027-09-01', 'out' => '2027-09-03'],
        ['uid' => 'b3@booking.com', 'in' => '2027-09-10', 'out' => '2027-09-12'],
    ]));
    $conflict = inTenant($fx['tenant'], fn () => InventoryConflict::query()->firstOrFail());

    return [$fx, $cal, $reservation, $conflict];
}

function blockedNights(array $fx, string $from, string $to): array
{
    return inTenant($fx['tenant'], function () use ($fx, $from, $to): array {
        $ranges = app(InventoryService::class)->blockedRanges($fx['unit'], StayRange::of($from, $to));
        $nights = [];

        foreach (StayRange::of($from, $to)->nightDates() as $night) {
            foreach ($ranges as $r) {
                if ($night->greaterThanOrEqualTo($r->checkIn) && $night->lessThan($r->checkOut)) {
                    $nights[] = $night->toDateString();
                    break;
                }
            }
        }

        return $nights;
    });
}

it('risolvere o ignorare un conflitto non libera le notti della prenotazione né dell\'evento', function (string $action) {
    [$fx, , , $conflict] = openConflict();
    $before = blockedNights($fx, '2027-07-10', '2027-07-16');

    inTenant($fx['tenant'], fn () => $action === 'resolve'
        ? app(ConflictService::class)->resolve($conflict, ConflictResolution::FALSE_POSITIVE, 'nota del gestore', null)
        : app(ConflictService::class)->ignore($conflict, 'nota del gestore', null));

    expect($before)->toBe(['2027-07-10', '2027-07-11', '2027-07-12', '2027-07-13', '2027-07-14', '2027-07-15'])
        ->and(blockedNights($fx, '2027-07-10', '2027-07-16'))->toBe($before);
})->with(['resolve', 'ignore']);

it('evento esterno rimosso con conferma → conflitto RESOLVED automatico, notifica; la prenotazione resta', function () {
    [$fx, $cal, $reservation, $conflict] = openConflict();
    $remaining = otaIcs('booking', [
        ['uid' => 'b2@booking.com', 'in' => '2027-09-01', 'out' => '2027-09-03'],
        ['uid' => 'b3@booking.com', 'in' => '2027-09-10', 'out' => '2027-09-12'],
    ]);

    syncWith($cal, $remaining);
    expect(inTenant($fx['tenant'], fn () => $conflict->refresh()->status))->toBe(ConflictStatus::OPEN);

    $this->travel(11)->minutes();
    syncWith($cal, $remaining);

    $conflict = inTenant($fx['tenant'], fn () => $conflict->refresh());
    $notified = inTenant($fx['tenant'], fn () => OutboxMessage::query()->where('type', 'calendar.conflict_auto_resolved')->count());

    expect($conflict->status)->toBe(ConflictStatus::RESOLVED)
        ->and($conflict->resolution)->toBe(ConflictResolution::CANCELLED_EXTERNAL)
        ->and($conflict->resolution_notes)->not->toBeNull()
        ->and($notified)->toBe(1)
        ->and(reloadReservation($fx, $reservation->id)->status)->toBe(ReservationStatus::CONFIRMED)
        ->and(blockedNights($fx, '2027-07-10', '2027-07-16'))->toBe(['2027-07-10', '2027-07-11', '2027-07-12', '2027-07-13']);
});

it('API: elenco, presa in carico, risoluzione con resolution obbligatoria, ignora con note; note mai nell\'audit', function () {
    [$fx, , $reservation, $conflict] = openConflict();
    signedInOwner($this, $fx['tenant']);

    $list = $this->getJson('/api/v1/admin/conflicts')->assertOk()->json('data');
    expect($list)->toHaveCount(1)
        ->and($list[0]['kind'])->toBe('RESERVATION')
        ->and($list[0]['reservation']['code'])->toBe($reservation->code)
        ->and($list[0]['external_event']['source'])->toBe('BOOKING');

    $this->postJson("/api/v1/admin/conflicts/{$conflict->id}/investigate")->assertOk()->assertJsonPath('data.status', 'INVESTIGATING');
    $this->postJson("/api/v1/admin/conflicts/{$conflict->id}/investigate")->assertStatus(409)->assertJsonPath('error.code', 'ILLEGAL_TRANSITION');
    $this->postJson("/api/v1/admin/conflicts/{$conflict->id}/resolve", [])->assertStatus(400);
    $this->postJson("/api/v1/admin/conflicts/{$conflict->id}/ignore", [])->assertStatus(400);
    $this->postJson("/api/v1/admin/conflicts/{$conflict->id}/resolve", ['resolution' => 'RELOCATED', 'notes' => 'Ospite Mario spostato'])
        ->assertOk()->assertJsonPath('data.status', 'RESOLVED')->assertJsonPath('data.resolution', 'RELOCATED');

    $audit = inTenant($fx['tenant'], fn () => AuditLog::query()->where('subject_id', $conflict->id)->get()->toJson());
    expect($audit)->not->toContain('Mario')
        ->and($this->getJson('/api/v1/admin/conflicts')->json('data'))->toBe([])
        ->and($this->getJson('/api/v1/admin/conflicts?status[]=RESOLVED')->json('data'))->toHaveCount(1);
});

it('API: conflitto di un altro tenant → 404', function () {
    [, , , $conflict] = openConflict();
    signedInOwner($this);

    $this->postJson("/api/v1/admin/conflicts/{$conflict->id}/investigate")->assertNotFound();
});
