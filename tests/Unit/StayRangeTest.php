<?php

use App\Domain\Reservations\StayRange;

/*
 * Soggiorni [check_in, check_out) (02; 04 "Notti"; CLAUDE.md regola 6).
 */

it('conta le notti escludendo il giorno di check-out', function () {
    $stay = StayRange::of('2027-08-10', '2027-08-17');

    expect($stay->nights())->toBe(7)
        ->and(array_map(fn ($d) => $d->toDateString(), $stay->nightDates()))
        ->toBe(['2027-08-10', '2027-08-11', '2027-08-12', '2027-08-13', '2027-08-14', '2027-08-15', '2027-08-16']);
});

it('converte da e verso il daterange di PostgreSQL in forma [)', function () {
    expect(StayRange::of('2027-08-10', '2027-08-17')->toPostgres())->toBe('[2027-08-10,2027-08-17)')
        ->and(StayRange::fromPostgres('[2027-08-10,2027-08-17)')->toPostgres())->toBe('[2027-08-10,2027-08-17)')
        ->and(StayRange::fromPostgres('[2027-08-10,2027-08-16]')->toPostgres())->toBe('[2027-08-10,2027-08-17)')
        ->and(StayRange::fromPostgres('(2027-08-09,2027-08-17)')->toPostgres())->toBe('[2027-08-10,2027-08-17)');
});

it('considera adiacenti e non sovrapposti due soggiorni consecutivi', function () {
    $first = StayRange::of('2027-08-10', '2027-08-17');

    expect($first->overlaps(StayRange::of('2027-08-17', '2027-08-20')))->toBeFalse()
        ->and($first->overlaps(StayRange::of('2027-08-16', '2027-08-20')))->toBeTrue()
        ->and($first->overlaps(StayRange::of('2027-08-01', '2027-08-11')))->toBeTrue();
});

it('rifiuta soggiorni vuoti, invertiti o date non valide', function (string $in, string $out) {
    StayRange::of($in, $out);
})->with([
    ['2027-08-10', '2027-08-10'],
    ['2027-08-17', '2027-08-10'],
    ['2027-02-30', '2027-03-02'],
    ['10/08/2027', '2027-08-17'],
])->throws(InvalidArgumentException::class);

it('rifiuta un daterange vuoto o illimitato', function (string $value) {
    StayRange::fromPostgres($value);
})->with(['empty', '[2027-08-10,)', '(,2027-08-17)'])->throws(InvalidArgumentException::class);
