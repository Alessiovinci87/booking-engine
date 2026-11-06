<?php

/*
 * 2.4: il PriceCalculator è una funzione pura (04): nessun accesso a database, rete, orologio,
 * configurazione o container. Vale per tutto App\Domain\Pricing\Calculator (calcolatore e DTO).
 */

arch('il calcolatore non usa Laravel, modelli, rete né orologio')
    ->expect('App\Domain\Pricing\Calculator')
    ->not->toUse([
        'Illuminate',
        'Carbon\Carbon',
        'App\Domain\Inventory\Models',
        'App\Domain\Pricing\Models',
        'App\Domain\Tenancy\TenantContext',
        'GuzzleHttp',
        'now',
        'today',
        'time',
        'microtime',
        'hrtime',
        'date',
        'config',
        'app',
        'env',
        'request',
        'random_int',
        'rand',
        'mt_rand',
        'file_get_contents',
        'fopen',
    ]);

it('il calcolatore non legge l\'orologio nemmeno tramite Carbon o DateTime', function () {
    $offenders = [];

    foreach (glob(dirname(__DIR__, 2).'/app/Domain/Pricing/Calculator/*.php') as $file) {
        $source = (string) file_get_contents($file);

        if (preg_match('/::now\(|::today\(|new\s+\\\\?DateTime(Immutable)?\(\s*\)|new\s+\\\\?DateTime(Immutable)?\(\s*[\'"]now/', $source) === 1) {
            $offenders[] = basename($file);
        }
    }

    expect($offenders)->toBe([]);
});
