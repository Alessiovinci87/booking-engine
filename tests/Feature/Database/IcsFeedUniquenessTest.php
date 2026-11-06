<?php

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/*
 * ADR-0009 (2a): un solo feed ACTIVE per unità e destinazione. Alla rotazione del token il vecchio
 * feed resta FROZEN per 7 giorni accanto al nuovo ACTIVE (06).
 */

function insertFeed(array $t, string $status, string $destination = 'AIRBNB'): void
{
    inTenant($t['tenant'], fn () => DB::table('ics_feeds')->insert([
        'id' => uuid7(), 'tenant_id' => $t['tenant'], 'unit_id' => $t['unit'], 'destination' => $destination,
        'token_encrypted' => 'cifrato-'.uuid7(), 'token_hash' => hash('sha256', uuid7()), 'status' => $status,
        'created_at' => now(), 'updated_at' => now(),
    ]));
}

function feedStatuses(array $t): array
{
    return inTenant($t['tenant'], fn () => DB::table('ics_feeds')->orderBy('status')->pluck('status')->all());
}

it('consente il vecchio feed FROZEN accanto al nuovo ACTIVE dopo la rotazione', function () {
    $t = twoTenantsWithUnits()['a'];

    insertFeed($t, 'FROZEN');
    insertFeed($t, 'ACTIVE');
    insertFeed($t, 'RETIRED');

    expect(feedStatuses($t))->toBe(['ACTIVE', 'FROZEN', 'RETIRED']);
});

it('consente più feed FROZEN per la stessa destinazione (offboarding dopo una rotazione)', function () {
    $t = twoTenantsWithUnits()['a'];

    insertFeed($t, 'FROZEN');
    insertFeed($t, 'FROZEN');

    expect(feedStatuses($t))->toBe(['FROZEN', 'FROZEN']);
});

it('rifiuta due feed ACTIVE per la stessa unità e destinazione', function () {
    $t = twoTenantsWithUnits()['a'];

    insertFeed($t, 'ACTIVE');
    insertFeed($t, 'ACTIVE');
})->throws(QueryException::class, 'ics_feeds_one_active_per_destination');

it('consente feed ACTIVE per destinazioni diverse', function () {
    $t = twoTenantsWithUnits()['a'];

    insertFeed($t, 'ACTIVE', 'AIRBNB');
    insertFeed($t, 'ACTIVE', 'BOOKING');

    expect(feedStatuses($t))->toBe(['ACTIVE', 'ACTIVE']);
});
