<?php

use App\Domain\Inventory\Enums\PaymentMode;
use App\Domain\Pricing\Calculator\PriceCalculator;
use App\Domain\Pricing\Calculator\PriceLine;
use App\Domain\Pricing\Calculator\PriceRequest;
use App\Domain\Pricing\Calculator\PricingError;
use App\Domain\Pricing\Calculator\PricingRatePlan;
use App\Domain\Pricing\Calculator\PricingSeason;
use App\Domain\Pricing\Calculator\PricingUnit;
use App\Domain\Reservations\StayRange;

/*
 * 2.4: PriceCalculator puro (04). Test golden esatto e casi limite elencati in 04: soggiorno a cavallo
 * di due stagioni, stagioni con stessa priorità, sconto pari al subtotale, totale sotto il minimo
 * Stripe, ospiti oltre il massimo. Nessun database: suite Unit.
 */

function goldenUnit(PaymentMode $mode = PaymentMode::DEPOSIT_30, array $overrides = []): PricingUnit
{
    $v = array_merge([
        'base' => 4, 'max' => 6, 'cleaning' => 6000, 'extra' => 1500, 'min' => 3,
    ], $overrides);

    return new PricingUnit($v['base'], $v['max'], $v['cleaning'], $v['extra'], $v['min'], $mode);
}

function seasonDto(string $id, string $start, string $endExclusive, int $cents, int $priority, ?int $min = null, string $createdAt = '2026-11-01 10:00:00'): PricingSeason
{
    return new PricingSeason($id, StayRange::of($start, $endExclusive), $cents, $priority, $min, new DateTimeImmutable($createdAt, new DateTimeZone('UTC')));
}

function goldenPlan(array $extraSeasons = [], int $base = 12000): PricingRatePlan
{
    return new PricingRatePlan($base, 'EUR', [
        seasonDto('agosto', '2027-08-01', '2027-09-01', 18000, 10, 5),
        seasonDto('ferragosto', '2027-08-13', '2027-08-18', 22000, 20),
        ...$extraSeasons,
    ]);
}

function stay(string $in, string $out, int $adults = 2, int $children = 0, int $discount = 0, bool $online = true): PriceRequest
{
    return new PriceRequest(StayRange::of($in, $out), $adults, $children, $discount, $online);
}

function linesOf(array $lines, string $kind): array
{
    return array_values(array_filter($lines, fn (PriceLine $l) => $l->kind->value === $kind));
}

it('calcola l\'esempio golden di 04: 1.585,00 €, caparra 475,50 €, saldo 1.109,50 €', function () {
    $quote = (new PriceCalculator)->calculate(goldenUnit(), goldenPlan(), stay('2027-08-10', '2027-08-17', adults: 4, children: 1));

    expect($quote->totalCents)->toBe(158500)
        ->and($quote->depositCents)->toBe(47550)
        ->and($quote->balanceCents)->toBe(110950)
        ->and($quote->subtotalCents)->toBe(158500)
        ->and($quote->discountCents)->toBe(0)
        ->and($quote->nights)->toBe(7)
        ->and($quote->guests)->toBe(5)
        ->and($quote->requiredMinNights)->toBe(5)
        ->and($quote->currency)->toBe('EUR')
        ->and($quote->paymentMode)->toBe(PaymentMode::DEPOSIT_30);

    $nights = linesOf($quote->lines, 'NIGHT');
    expect(array_map(fn (PriceLine $l) => [$l->date, $l->amountCents, $l->seasonId], $nights))->toBe([
        ['2027-08-10', 18000, 'agosto'],
        ['2027-08-11', 18000, 'agosto'],
        ['2027-08-12', 18000, 'agosto'],
        ['2027-08-13', 22000, 'ferragosto'],
        ['2027-08-14', 22000, 'ferragosto'],
        ['2027-08-15', 22000, 'ferragosto'],
        ['2027-08-16', 22000, 'ferragosto'],
    ]);

    $extra = linesOf($quote->lines, 'EXTRA_GUEST');
    expect($extra)->toHaveCount(7)
        ->and($extra[0]->quantity)->toBe(1)
        ->and($extra[0]->unitAmountCents)->toBe(1500)
        ->and(array_sum(array_map(fn (PriceLine $l) => $l->amountCents, $extra)))->toBe(10500);

    $cleaning = linesOf($quote->lines, 'CLEANING');
    expect($cleaning)->toHaveCount(1)->and($cleaning[0]->amountCents)->toBe(6000)
        ->and(linesOf($quote->lines, 'DISCOUNT'))->toBe([])
        ->and(array_sum(array_map(fn (PriceLine $l) => $l->amountCents, $quote->lines)))->toBe($quote->totalCents);
});

it('il check-out non è una notte', function () {
    $quote = (new PriceCalculator)->calculate(goldenUnit(), goldenPlan(), stay('2027-08-10', '2027-08-17'));

    expect(array_column(array_map(fn (PriceLine $l) => $l->toArray(), linesOf($quote->lines, 'NIGHT')), 'date'))
        ->not->toContain('2027-08-17');
});

it('soggiorno a cavallo di due stagioni e del prezzo base', function () {
    $plan = goldenPlan([seasonDto('luglio', '2027-07-01', '2027-08-01', 15000, 10)]);
    // 29, 30, 31 luglio (luglio), 1, 2 agosto (agosto), poi base dal 1° settembre in un altro caso.
    $quote = (new PriceCalculator)->calculate(goldenUnit(overrides: ['min' => 1]), $plan, stay('2027-07-29', '2027-08-03'));

    expect(array_map(fn (PriceLine $l) => $l->amountCents, linesOf($quote->lines, 'NIGHT')))->toBe([15000, 15000, 15000, 18000, 18000])
        // Agosto (vincente il 1° e il 2) chiede 5 notti: il soggiorno ne ha 5.
        ->and($quote->requiredMinNights)->toBe(5)
        ->and($quote->totalCents)->toBe(81000 + 6000);

    $september = (new PriceCalculator)->calculate(goldenUnit(), goldenPlan(), stay('2027-08-27', '2027-09-03'));
    expect(array_map(fn (PriceLine $l) => $l->amountCents, linesOf($september->lines, 'NIGHT')))
        ->toBe([18000, 18000, 18000, 18000, 18000, 12000, 12000]);
});

it('a parità di priorità vince l\'intervallo più corto, poi la stagione più recente', function () {
    $plan = new PricingRatePlan(12000, 'EUR', [
        seasonDto('lunga', '2027-06-01', '2027-07-01', 10000, 5),
        seasonDto('corta', '2027-06-10', '2027-06-20', 11000, 5),
        seasonDto('uguale-vecchia', '2027-06-15', '2027-06-25', 13000, 5, createdAt: '2026-11-01 10:00:00'),
        seasonDto('uguale-nuova', '2027-06-15', '2027-06-25', 14000, 5, createdAt: '2026-11-02 10:00:00'),
    ]);
    $quote = (new PriceCalculator)->calculate(goldenUnit(overrides: ['min' => 1]), $plan, stay('2027-06-09', '2027-06-16'));

    expect(array_map(fn (PriceLine $l) => $l->seasonId, linesOf($quote->lines, 'NIGHT')))->toBe([
        'lunga',                                  // 9: solo la lunga
        'corta', 'corta', 'corta', 'corta', 'corta', // 10-14: corta (10 notti) batte lunga (30)
        'uguale-nuova',                           // 15: tre da 10 notti; vince la più recente
    ]);

    // Ordine delle stagioni nell'input irrilevante.
    $reversed = new PricingRatePlan(12000, 'EUR', array_reverse($plan->seasons));
    expect((new PriceCalculator)->calculate(goldenUnit(overrides: ['min' => 1]), $reversed, stay('2027-06-09', '2027-06-16'))->totalCents)
        ->toBe($quote->totalCents);
});

it('il minimo notti viene dalle sole stagioni vincenti; i minimi nulli non contano', function () {
    // 13→17: tutte notti di Ferragosto (minimo nullo) anche se dentro Agosto (minimo 5): basta il minimo dell'unità.
    $quote = (new PriceCalculator)->calculate(goldenUnit(), goldenPlan(), stay('2027-08-13', '2027-08-17'));
    expect($quote->requiredMinNights)->toBe(3)->and($quote->nights)->toBe(4);

    // 10→14: notti di Agosto vincenti, minimo 5 non raggiunto.
    expect(fn () => (new PriceCalculator)->calculate(goldenUnit(), goldenPlan(), stay('2027-08-10', '2027-08-14')))
        ->toThrow(function (PricingError $e) {
            expect($e->errorCode)->toBe('MIN_NIGHTS_NOT_MET')
                ->and($e->httpStatus)->toBe(422)
                ->and($e->details)->toBe(['required_min_nights' => 5, 'nights' => 4]);
        });

    // Fuori stagione vale il minimo dell'unità.
    expect(fn () => (new PriceCalculator)->calculate(goldenUnit(), goldenPlan(), stay('2027-10-01', '2027-10-03')))
        ->toThrow(fn (PricingError $e) => expect($e->details['required_min_nights'])->toBe(3));
});

it('rifiuta più ospiti della capienza massima, bambini compresi', function () {
    expect(fn () => (new PriceCalculator)->calculate(goldenUnit(), goldenPlan(), stay('2027-08-10', '2027-08-17', adults: 5, children: 2)))
        ->toThrow(function (PricingError $e) {
            expect($e->errorCode)->toBe('OCCUPANCY_EXCEEDED')
                ->and($e->details)->toBe(['max_occupancy' => 6, 'guests' => 7]);
        });

    // Esattamente la capienza massima: 2 extra per notte.
    $quote = (new PriceCalculator)->calculate(goldenUnit(), goldenPlan(), stay('2027-08-10', '2027-08-17', adults: 3, children: 3));
    expect(linesOf($quote->lines, 'EXTRA_GUEST')[0]->quantity)->toBe(2)
        ->and(linesOf($quote->lines, 'EXTRA_GUEST')[0]->amountCents)->toBe(3000);
});

it('nessuna riga extra fino alla capienza base e nessuna pulizia se gratuita', function () {
    $quote = (new PriceCalculator)->calculate(goldenUnit(overrides: ['cleaning' => 0, 'min' => 1]), goldenPlan(), stay('2027-10-01', '2027-10-02', adults: 4));

    expect(array_map(fn (PriceLine $l) => $l->kind->value, $quote->lines))->toBe(['NIGHT'])
        ->and($quote->totalCents)->toBe(12000);
});

it('applica lo sconto come riga negativa; sconto pari al subtotale', function () {
    $calc = new PriceCalculator;
    $unit = goldenUnit(overrides: ['min' => 1]);

    $discounted = $calc->calculate($unit, goldenPlan(), stay('2027-10-01', '2027-10-03', discount: 4000));
    $discount = linesOf($discounted->lines, 'DISCOUNT');
    expect($discounted->subtotalCents)->toBe(30000)
        ->and($discounted->totalCents)->toBe(26000)
        ->and($discount[0]->amountCents)->toBe(-4000)
        ->and(array_sum(array_map(fn (PriceLine $l) => $l->amountCents, $discounted->lines)))->toBe(26000);

    // Sconto pari al subtotale: totale 0, ammesso solo senza pagamento online (es. prenotazione manuale).
    $free = $calc->calculate($unit, goldenPlan(), stay('2027-10-01', '2027-10-03', discount: 30000, online: false));
    expect([$free->totalCents, $free->depositCents, $free->balanceCents])->toBe([0, 0, 0]);

    expect(fn () => $calc->calculate($unit, goldenPlan(), stay('2027-10-01', '2027-10-03', discount: 30000)))
        ->toThrow(fn (PricingError $e) => expect($e->errorCode)->toBe('AMOUNT_BELOW_MINIMUM'));

    // Oltre il subtotale: errore di validazione.
    expect(fn () => $calc->calculate($unit, goldenPlan(), stay('2027-10-01', '2027-10-03', discount: 30001, online: false)))
        ->toThrow(function (PricingError $e) {
            expect($e->errorCode)->toBe('VALIDATION_FAILED')
                ->and($e->details['fields'])->toBe(['discount_cents' => ['DISCOUNT_OUT_OF_RANGE']]);
        });
});

it('rifiuta un totale sotto il minimo Stripe di 50 centesimi solo se si paga online', function () {
    $unit = goldenUnit(overrides: ['cleaning' => 0, 'min' => 1]);
    $calc = new PriceCalculator;

    expect(fn () => $calc->calculate($unit, new PricingRatePlan(49, 'EUR', []), stay('2027-10-01', '2027-10-02')))
        ->toThrow(function (PricingError $e) {
            expect($e->errorCode)->toBe('AMOUNT_BELOW_MINIMUM')
                ->and($e->httpStatus)->toBe(422)
                ->and($e->details)->toBe(['minimum_cents' => 50, 'total_cents' => 49]);
        });

    expect($calc->calculate($unit, new PricingRatePlan(50, 'EUR', []), stay('2027-10-01', '2027-10-02'))->totalCents)->toBe(50)
        ->and($calc->calculate($unit, new PricingRatePlan(49, 'EUR', []), stay('2027-10-01', '2027-10-02', online: false))->totalCents)->toBe(49);
});

it('caparra 30% arrotondata per eccesso al centesimo, saldo = totale − caparra', function (int $total, int $deposit) {
    [$d, $b] = PriceCalculator::split($total, PaymentMode::DEPOSIT_30);

    expect($d)->toBe($deposit)->and($d + $b)->toBe($total);
})->with([
    [158500, 47550],
    [1001, 301],
    [1000, 300],
    [1, 1],
    [0, 0],
    [99999, 30000],
]);

it('pagamento FULL: caparra pari al totale e saldo zero', function () {
    $quote = (new PriceCalculator)->calculate(goldenUnit(PaymentMode::FULL), goldenPlan(), stay('2027-08-10', '2027-08-17', adults: 5));

    expect([$quote->totalCents, $quote->depositCents, $quote->balanceCents])->toBe([158500, 158500, 0]);
});

it('simula il prezzo per canale come in 04 (100,00 € al 15% → 117,65 €)', function () {
    expect(PriceCalculator::suggestedOtaPrice(10000, 1500))->toBe(11765)
        ->and(PriceCalculator::suggestedOtaPrice(10000, 0))->toBe(10000)
        ->and(PriceCalculator::suggestedOtaPrice(8500, 1500))->toBe(10000);
});

it('rifiuta adulti mancanti o valori negativi', function (int $adults, int $children, int $discount) {
    (new PriceCalculator)->calculate(goldenUnit(), goldenPlan(), stay('2027-10-01', '2027-10-05', $adults, $children, $discount));
})->with([[0, 2, 0], [2, -1, 0], [2, 0, -1]])->throws(InvalidArgumentException::class);
