<?php

use App\Domain\Inventory\Models\Unit;
use App\Domain\Pricing\Calculator\PriceLine;
use App\Domain\Pricing\Calculator\PriceRequest;
use App\Domain\Pricing\PriceQuoteService;
use App\Domain\Reservations\Enums\ReservationStatus;
use App\Domain\Reservations\StayRange;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/*
 * I14, parte prezzi (11, criteri di uscita di M2): il calcolo produce righe autosufficienti (data,
 * quantità, importo) che si possono congelare così come sono; un cambio di tariffe cambia i calcoli
 * successivi ma non una copia già fatta. La copia in reservation_price_lines e quotes.price_lines
 * arriva con M3 e M5, che estendono questo test.
 */

it('le righe calcolate sono una copia autosufficiente che non segue le tariffe', function () {
    $tenant = createTenantRow();
    $golden = goldenUnitRows($tenant);
    $request = new PriceRequest(StayRange::of('2027-08-10', '2027-08-17'), 4, 1);

    [$frozen, $total] = inTenant($tenant, function () use ($golden, $request) {
        $quote = app(PriceQuoteService::class)->quote(Unit::query()->findOrFail($golden['unit']), $request);

        return [json_encode(array_map(fn (PriceLine $l) => $l->toArray(), $quote->lines)), $quote->totalCents];
    });

    // Il gestore cambia le tariffe dopo il calcolo.
    inTenant($tenant, function () use ($golden) {
        DB::table('season_rules')->where('id', $golden['seasons']['ferragosto'])->update(['nightly_cents' => 30000]);
        DB::table('rate_plans')->where('id', $golden['plan'])->update(['base_nightly_cents' => 99000]);
        DB::table('units')->where('id', $golden['unit'])->update(['cleaning_fee_cents' => 9000]);
    });

    $recalculated = inTenant($tenant, fn () => app(PriceQuoteService::class)->quote(Unit::query()->findOrFail($golden['unit']), $request));
    $lines = json_decode($frozen, true);

    expect($total)->toBe(158500)
        ->and(array_sum(array_column($lines, 'amount_cents')))->toBe(158500)
        // Righe per notte: NIGHT ed EXTRA_GUEST alternate.
        ->and($lines[4])->toMatchArray(['kind' => 'NIGHT', 'date' => '2027-08-12', 'amount_cents' => 18000])
        ->and($lines[6])->toMatchArray(['kind' => 'NIGHT', 'date' => '2027-08-13', 'amount_cents' => 22000])
        ->and($recalculated->totalCents)->toBe(158500 + 4 * 8000 + 3000)
        ->and(array_sum(array_column($lines, 'amount_cents')))->toBe(158500);
});

it('le righe di una prenotazione restano congelate quando cambiano le tariffe (M3)', function () {
    Carbon::setTestNow('2027-06-01 10:00:00');
    $fx = bookableTenant('INSTANT');
    $reservation = reservationIn($fx, ReservationStatus::CONFIRMED);
    $snapshot = fn () => inTenant($fx['tenant'], fn () => DB::table('reservation_price_lines')->where('reservation_id', $reservation->id)
        ->orderBy('position')->get(['kind', 'night_date', 'quantity', 'unit_amount_cents', 'amount_cents'])->map(fn ($r) => (array) $r)->all());
    $before = $snapshot();

    inTenant($fx['tenant'], function () use ($fx) {
        DB::table('rate_plans')->where('unit_id', $fx['unit'])->update(['base_nightly_cents' => 99000]);
        DB::table('units')->where('id', $fx['unit'])->update(['cleaning_fee_cents' => 9000]);
    });

    $after = reloadReservation($fx, $reservation->id);
    signedInOwner($this, $fx['tenant']);

    expect($before)->toHaveCount(5)
        ->and(array_sum(array_column($before, 'amount_cents')))->toBe(54000)
        ->and($snapshot())->toBe($before)
        ->and($after->contract_total_cents)->toBe(54000)
        ->and($after->deposit_due_cents)->toBe(16200);

    panelGet($this, "/api/v1/admin/reservations/{$reservation->id}")
        ->assertOk()
        ->assertJsonPath('contract_total.amount_cents', 54000)
        ->assertJsonPath('price_lines.0.unit_amount.amount_cents', 12000)
        ->assertJsonCount(5, 'price_lines');

    Carbon::setTestNow();
});
