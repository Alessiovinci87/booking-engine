<?php

use Illuminate\Support\Facades\DB;

/*
 * AC5 e I1: 20 processi PHP veri (Symfony Process) chiedono in parallelo le stesse notti. Ne vince uno
 * solo, gli altri ricevono 409 DATES_UNAVAILABLE; resta una sola occupazione attiva. I dati di
 * partenza sono confermati nel database (vedi tests/Support/concurrency.php) e cancellati alla fine.
 */

beforeEach(function () {
    $this->setup = committedTenantWithUnit();
});

afterEach(function () {
    deleteCommittedTenant($this->setup['tenant']);
});

it('20 blocchi paralleli sulle stesse notti: una sola occupazione', function () {
    $results = runConcurrently('acquire-block.php', [$this->setup['tenant'], $this->setup['unit'], '2027-08-10', '2027-08-17'], 20);

    $codes = collect($results)->map(fn (array $r) => $r['ok'] ? 'OK' : $r['code'])->countBy()->sortKeys()->all();
    expect($codes)->toBe(['DATES_UNAVAILABLE' => 19, 'OK' => 1], json_encode($results));

    $active = DB::connection(committedConnection('pgsql', $this->setup['tenant']))
        ->table('inventory_occupancies')->where('unit_id', $this->setup['unit'])->where('status', 'ACTIVE')->count();
    expect($active)->toBe(1);
});
