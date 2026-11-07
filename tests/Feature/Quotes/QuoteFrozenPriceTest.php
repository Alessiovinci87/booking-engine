<?php

use App\Domain\Pricing\Enums\PriceLineKind;
use App\Domain\Reservations\Models\ReservationPriceLine;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/*
 * AC16 e I14 (01, 02), 04 "Preventivi": il prezzo del preventivo si calcola alla creazione e si
 * congela; se le tariffe cambiano, l'accettazione usa righe e totale del preventivo senza ricalcolo.
 * Modalità di pagamento e prenotazione congelate; con un calendario esterno attivo prevale REQUEST.
 */

beforeEach(fn () => Carbon::setTestNow('2027-06-01 10:00:00'));
afterEach(fn () => Carbon::setTestNow());

it('tariffe, pulizia e modalità di pagamento cambiate: la prenotazione ha il prezzo del preventivo', function () {
    $fx = bookableTenant();
    $quote = quoteFor($fx);

    inTenant($fx['tenant'], function () use ($fx) {
        DB::table('rate_plans')->where('unit_id', $fx['unit'])->update(['base_nightly_cents' => 30000]);
        DB::table('units')->where('id', $fx['unit'])->update(['cleaning_fee_cents' => 20000, 'payment_mode' => 'FULL']);
    });

    // La pagina ospite mostra il prezzo congelato.
    $this->getJson('/api/v1/public/quotes/'.quoteToken($quote))->assertOk()
        ->assertJsonPath('total.amount_cents', 50000)
        ->assertJsonPath('deposit.amount_cents', 15000)
        ->assertJsonPath('payment_mode', 'DEPOSIT_30');

    $code = acceptQuote($this, quoteToken($quote))->assertCreated()->json('reservation_code');
    $reservation = quoteReservation($fx, $code);
    $lines = inTenant($fx['tenant'], fn () => ReservationPriceLine::query()->where('reservation_id', $reservation->id)->orderBy('position')->get());

    expect($reservation->contract_total_cents)->toBe(50000)
        ->and($reservation->deposit_due_cents)->toBe(15000)
        ->and($reservation->balance_due_cents)->toBe(35000)
        ->and($reservation->payment_mode_snapshot->value)->toBe('DEPOSIT_30')
        ->and($lines->where('kind', PriceLineKind::NIGHT)->pluck('amount_cents')->unique()->values()->all())->toBe([12000])
        ->and($lines->firstWhere('kind', PriceLineKind::CLEANING)->amount_cents)->toBe(6000)
        ->and($lines->firstWhere('kind', PriceLineKind::DISCOUNT)->amount_cents)->toBe(-4000)
        ->and($lines->sum('amount_cents'))->toBe(50000)
        ->and(latestPayment($fx, $reservation->id)->amount_cents)->toBe(15000);
});

it('modalità INSTANT congelata, ma un calendario esterno aggiunto dopo impone REQUEST (I12)', function () {
    $fx = bookableTenant();
    $quote = quoteFor($fx);
    calendarOn($fx, 'AIRBNB');

    $this->getJson('/api/v1/public/quotes/'.quoteToken($quote))->assertOk()->assertJsonPath('booking_mode', 'REQUEST');

    $code = acceptQuote($this, quoteToken($quote))->assertCreated()->json('reservation_code');
    $reservation = quoteReservation($fx, $code);

    expect($reservation->booking_mode_snapshot->value)->toBe('REQUEST')
        ->and(latestPayment($fx, $reservation->id)->capture_method->value)->toBe('MANUAL');
});

it('capienza ridotta dopo il preventivo: 409 OCCUPANCY_EXCEEDED all\'accettazione', function () {
    $fx = bookableTenant();
    $quote = quoteFor($fx, ['adults' => 5]);
    inTenant($fx['tenant'], fn () => DB::table('units')->where('id', $fx['unit'])->update(['max_occupancy' => 4, 'base_occupancy' => 2]));

    acceptQuote($this, quoteToken($quote))->assertStatus(409)->assertJsonPath('error.code', 'OCCUPANCY_EXCEEDED');
});
