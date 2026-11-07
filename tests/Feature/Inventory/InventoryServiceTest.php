<?php

use App\Domain\Inventory\Enums\OccupancyPhase;
use App\Domain\Inventory\Enums\OccupancyTempReason;
use App\Domain\Inventory\InventoryService;
use App\Domain\Inventory\Models\Unit;
use App\Domain\Reservations\Models\Reservation;
use App\Domain\Reservations\StayRange;
use App\Domain\Tenancy\Exceptions\BusinessRuleViolation;
use Illuminate\Support\Facades\DB;

/*
 * 3.1: InventoryService come in 02 ("Funzione unica di scrittura dell'inventario"). Lock sull'unità,
 * verifiche su eventi esterni e conflitti, vincolo GiST tradotto in 409 DATES_UNAVAILABLE, coerenza
 * con lo stato della prenotazione (I15), UID iCal stabile.
 */

beforeEach(function () {
    $this->tenant = createTenantRow();
    $this->unit = createUnitRow($this->tenant, createPropertyRow($this->tenant));
    $this->inventory = app(InventoryService::class);
});

function unitModel(string $tenant, string $unit): Unit
{
    return inTenant($tenant, fn () => Unit::query()->findOrFail($unit));
}

/** Prenotazione inserita come riga (lo stato si scrive qui solo per preparare il test). */
function reservationModel(string $tenant, string $unit, string $stay, string $status = 'AWAITING_PAYMENT'): Reservation
{
    $row = reservationRow($tenant, $unit, createGuestRow($tenant), ['stay' => $stay, 'status' => $status,
        'confirmation_kind' => in_array($status, ['CONFIRMED', 'CANCELLED'], true) ? 'ADMINISTRATIVE' : null]);
    inTenant($tenant, fn () => DB::table('reservations')->insert($row));

    return inTenant($tenant, fn () => Reservation::query()->findOrFail($row['id']));
}

function acquireReservation(string $tenant, string $unit, string $checkIn, string $checkOut, string $status = 'AWAITING_PAYMENT'): Reservation
{
    $reservation = null;

    inTenant($tenant, function () use ($tenant, $unit, $checkIn, $checkOut, $status, &$reservation) {
        app(InventoryService::class)->acquireForReservation(
            unitModel($tenant, $unit),
            StayRange::of($checkIn, $checkOut),
            OccupancyPhase::TEMPORARY,
            OccupancyTempReason::CHECKOUT,
            now()->addMinutes(45)->toImmutable(),
            function () use ($tenant, $unit, $checkIn, $checkOut, $status, &$reservation): Reservation {
                return $reservation = reservationModel($tenant, $unit, "[{$checkIn},{$checkOut})", $status);
            },
        );
    });

    return $reservation;
}

function occupancies(string $tenant, string $unit): array
{
    return inTenant($tenant, fn () => DB::table('inventory_occupancies')->where('unit_id', $unit)->orderBy('created_at')->orderBy('id')->get()->all());
}

it('acquisisce un\'occupazione temporanea per la prenotazione con UID iCal stabile e audit', function () {
    $reservation = acquireReservation($this->tenant, $this->unit, '2027-08-10', '2027-08-17');

    [$occupancy] = occupancies($this->tenant, $this->unit);
    expect($occupancy->reservation_id)->toBe($reservation->id)
        ->and($occupancy->kind)->toBe('RESERVATION')
        ->and($occupancy->phase)->toBe('TEMPORARY')
        ->and($occupancy->temp_reason)->toBe('CHECKOUT')
        ->and($occupancy->stay)->toBe('[2027-08-10,2027-08-17)')
        ->and($occupancy->ics_uid)->toBe("occ-{$occupancy->id}@".InventoryService::icsDomain())
        ->and(inTenant($this->tenant, fn () => DB::table('audit_log')->where('action', 'occupancy.acquired')->count()))->toBe(1);
});

it('rifiuta date sovrapposte con 409 DATES_UNAVAILABLE e le notti occupate', function () {
    acquireReservation($this->tenant, $this->unit, '2027-08-10', '2027-08-17');

    try {
        acquireReservation($this->tenant, $this->unit, '2027-08-15', '2027-08-19');
        $this->fail('Attesa DATES_UNAVAILABLE');
    } catch (BusinessRuleViolation $e) {
        expect($e->httpStatus)->toBe(409)
            ->and($e->errorCode)->toBe('DATES_UNAVAILABLE')
            ->and($e->details['nights'])->toBe(['2027-08-15', '2027-08-16']);
    }

    // La prenotazione della richiesta rifiutata non resta nel database (stessa transazione).
    expect(occupancies($this->tenant, $this->unit))->toHaveCount(1)
        ->and(inTenant($this->tenant, fn () => DB::table('reservations')->count()))->toBe(1);
});

it('traduce la violazione del vincolo GiST (23P01) in DATES_UNAVAILABLE', function () {
    // Riga inserita senza passare dal servizio: il vincolo del database resta la garanzia finale.
    inTenant($this->tenant, fn () => DB::table('inventory_occupancies')->insert([
        'id' => $id = uuid7(), 'tenant_id' => $this->tenant, 'unit_id' => $this->unit, 'stay' => '[2027-08-12,2027-08-14)',
        'kind' => 'MANUAL_BLOCK', 'phase' => 'FIRM', 'status' => 'ACTIVE', 'ics_uid' => "occ-{$id}@example.test",
        'created_at' => now(), 'updated_at' => now(),
    ]));

    expect(fn () => inTenant($this->tenant, fn () => $this->inventory->acquireBlock(unitModel($this->tenant, $this->unit), StayRange::of('2027-08-13', '2027-08-15'), null)))
        ->toThrow(fn (BusinessRuleViolation $e) => expect($e->errorCode)->toBe('DATES_UNAVAILABLE'));
});

it('accetta un soggiorno che inizia il giorno di check-out di un altro', function () {
    acquireReservation($this->tenant, $this->unit, '2027-08-10', '2027-08-17');
    acquireReservation($this->tenant, $this->unit, '2027-08-17', '2027-08-20');

    expect(occupancies($this->tenant, $this->unit))->toHaveCount(2);
});

it('rifiuta date occupate da un evento esterno attivo o in rimozione, di qualsiasi tipo di eco', function (string $status) {
    createExternalEventRow($this->tenant, $this->unit, '[2027-08-12,2027-08-14)', $status);

    expect(fn () => acquireReservation($this->tenant, $this->unit, '2027-08-10', '2027-08-17'))
        ->toThrow(fn (BusinessRuleViolation $e) => expect($e->errorCode)->toBe('DATES_UNAVAILABLE')
            ->and($e->details['nights'])->toBe(['2027-08-12', '2027-08-13']));
})->with(['ACTIVE', 'PENDING_REMOVAL']);

it('ignora gli eventi esterni rimossi e i conflitti chiusi', function () {
    createExternalEventRow($this->tenant, $this->unit, '[2027-08-12,2027-08-14)', 'REMOVED');
    createConflictRow($this->tenant, $this->unit, '[2027-08-12,2027-08-14)', 'RESOLVED');
    createConflictRow($this->tenant, $this->unit, '[2027-08-14,2027-08-15)', 'IGNORED');

    acquireReservation($this->tenant, $this->unit, '2027-08-10', '2027-08-17');

    expect(occupancies($this->tenant, $this->unit))->toHaveCount(1);
});

it('rifiuta date con un conflitto aperto o in verifica', function (string $status) {
    createConflictRow($this->tenant, $this->unit, '[2027-08-16,2027-08-18)', $status);

    expect(fn () => acquireReservation($this->tenant, $this->unit, '2027-08-10', '2027-08-17'))
        ->toThrow(fn (BusinessRuleViolation $e) => expect($e->details['nights'])->toBe(['2027-08-16']));
})->with(['OPEN', 'INVESTIGATING']);

it('rifiuta un\'unità non attiva o con vendite sospese con 409 UNIT_NOT_BOOKABLE', function (array $change) {
    inTenant($this->tenant, fn () => DB::table('units')->where('id', $this->unit)->update($change));

    expect(fn () => acquireReservation($this->tenant, $this->unit, '2027-08-10', '2027-08-17'))
        ->toThrow(fn (BusinessRuleViolation $e) => expect($e->errorCode)->toBe('UNIT_NOT_BOOKABLE'));

    expect(inTenant($this->tenant, fn () => DB::table('reservations')->count()))->toBe(0);
})->with([
    'non attiva' => [['status' => 'INACTIVE']],
    'vendite sospese' => [['sales_suspended' => true]],
]);

it('consente i blocchi manuali anche con le vendite sospese e li rilascia', function () {
    inTenant($this->tenant, fn () => DB::table('units')->where('id', $this->unit)->update(['sales_suspended' => true]));

    $block = inTenant($this->tenant, fn () => $this->inventory->acquireBlock(unitModel($this->tenant, $this->unit), StayRange::of('2027-08-10', '2027-08-12'), 'Lavori'));
    inTenant($this->tenant, fn () => $this->inventory->releaseBlock($block->id, 'manual'));

    [$row] = occupancies($this->tenant, $this->unit);
    expect($row->status)->toBe('RELEASED')->and($row->note)->toBe('Lavori')
        ->and(inTenant($this->tenant, fn () => DB::table('audit_log')->where('action', 'occupancy.block_created')->value('changes')))
        ->toContain('[personal]')->not->toContain('Lavori');
});

it('promuove, cambia motivo e scadenza sulla stessa riga', function () {
    $reservation = acquireReservation($this->tenant, $this->unit, '2027-08-10', '2027-08-17');
    [$before] = occupancies($this->tenant, $this->unit);

    inTenant($this->tenant, fn () => $this->inventory->setTemporaryReason($reservation, OccupancyTempReason::AWAITING_APPROVAL, now()->addHours(12)->toImmutable()));
    expect(occupancies($this->tenant, $this->unit)[0]->temp_reason)->toBe('AWAITING_APPROVAL');

    inTenant($this->tenant, fn () => $this->inventory->promoteToFirm($reservation));
    [$after] = occupancies($this->tenant, $this->unit);

    expect($after->id)->toBe($before->id)
        ->and($after->ics_uid)->toBe($before->ics_uid)
        ->and($after->phase)->toBe('FIRM')
        ->and($after->temp_reason)->toBeNull()
        ->and($after->expires_at)->toBeNull();
});

it('sposta le date sulla stessa occupazione escludendo sé stessa', function () {
    $reservation = acquireReservation($this->tenant, $this->unit, '2027-08-10', '2027-08-17');
    acquireReservation($this->tenant, $this->unit, '2027-08-20', '2027-08-25');

    inTenant($this->tenant, fn () => $this->inventory->move($reservation, StayRange::of('2027-08-12', '2027-08-19')));
    expect(occupancies($this->tenant, $this->unit)[0]->stay)->toBe('[2027-08-12,2027-08-19)');

    expect(fn () => inTenant($this->tenant, fn () => $this->inventory->move($reservation, StayRange::of('2027-08-18', '2027-08-21'))))
        ->toThrow(fn (BusinessRuleViolation $e) => expect($e->errorCode)->toBe('DATES_UNAVAILABLE')
            ->and($e->details['nights'])->toBe(['2027-08-20']));
    expect(occupancies($this->tenant, $this->unit)[0]->stay)->toBe('[2027-08-12,2027-08-19)');
});

it('rilascia solo l\'occupazione di una prenotazione in stato terminale (I15)', function () {
    $reservation = acquireReservation($this->tenant, $this->unit, '2027-08-10', '2027-08-17');

    expect(fn () => inTenant($this->tenant, fn () => $this->inventory->release($reservation, 'expired')))->toThrow(LogicException::class);

    inTenant($this->tenant, fn () => DB::table('reservations')->where('id', $reservation->id)->update(['status' => 'EXPIRED']));
    inTenant($this->tenant, fn () => $this->inventory->release($reservation->refresh(), 'expired'));

    [$row] = occupancies($this->tenant, $this->unit);
    expect($row->status)->toBe('RELEASED')->and($row->release_reason)->toBe('expired')->and($row->released_at)->not->toBeNull();

    // Le date sono di nuovo libere.
    acquireReservation($this->tenant, $this->unit, '2027-08-10', '2027-08-17');
    expect(occupancies($this->tenant, $this->unit))->toHaveCount(2);
});

it('non promuove né sposta un\'occupazione di una prenotazione terminale', function () {
    $reservation = acquireReservation($this->tenant, $this->unit, '2027-08-10', '2027-08-17');
    inTenant($this->tenant, fn () => DB::table('reservations')->where('id', $reservation->id)->update(['status' => 'EXPIRED']));

    expect(fn () => inTenant($this->tenant, fn () => $this->inventory->promoteToFirm($reservation->refresh())))->toThrow(LogicException::class);
});
