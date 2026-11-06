<?php

/*
 * Struttura del codice di ADR-0006: logica di dominio in app/Domain/<Area>/, controller sottili in
 * app/Http/Controllers/Api/V1/{Admin,Public}.
 */

it('ha le aree di dominio e le cartelle dei controller previste da ADR-0006', function (string $path) {
    expect(dirname(__DIR__, 2).'/'.$path)->toBeDirectory();
})->with([
    'app/Domain/Inventory',
    'app/Domain/Reservations',
    'app/Domain/Pricing',
    'app/Domain/Payments',
    'app/Domain/Calendar',
    'app/Domain/Quotes',
    'app/Domain/Privacy',
    'app/Domain/Tenancy',
    'app/Http/Controllers/Api/V1/Admin',
    'app/Http/Controllers/Api/V1/Public',
]);

arch('il dominio non dipende dai controller HTTP')
    ->expect('App\Domain')
    ->not->toUse('App\Http\Controllers');

arch('nessuna funzione di debug dimenticata')
    ->expect(['dd', 'dump', 'ray', 'var_dump', 'print_r', 'die', 'exit'])
    ->not->toBeUsed();
