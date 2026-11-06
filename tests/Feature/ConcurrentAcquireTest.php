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

it('20 checkout paralleli dell\'ospite sulle stesse notti: una sola prenotazione e una sola occupazione', function () {
    $tenant = $this->setup['tenant'];
    $db = DB::connection(committedConnection('pgsql', $tenant));
    $now = now();
    $policy = uuid7();
    $db->table('rate_plans')->insert(['id' => uuid7(), 'tenant_id' => $tenant, 'unit_id' => $this->setup['unit'], 'name' => 'Base', 'base_nightly_cents' => 12000, 'currency' => 'EUR', 'active' => true, 'created_at' => $now, 'updated_at' => $now]);
    $db->table('cancellation_policies')->insert(['id' => $policy, 'tenant_id' => $tenant, 'property_id' => $this->setup['property'], 'code' => 'FLEXIBLE_7D', 'created_at' => $now, 'updated_at' => $now]);
    $db->table('properties')->where('id', $this->setup['property'])->update(['status' => 'PUBLISHED', 'default_cancellation_policy_id' => $policy]);
    $db->table('units')->where('id', $this->setup['unit'])->update(['booking_mode' => 'INSTANT']);
    $db->table('stripe_accounts')->insert(['id' => uuid7(), 'tenant_id' => $tenant, 'stripe_account_id' => 'acct_conc_'.substr(uuid7(), -10), 'charges_enabled' => true, 'created_at' => $now, 'updated_at' => $now]);

    $results = runConcurrently('start-checkout.php', [$tenant, $this->setup['unit'], '2027-08-10', '2027-08-17'], 20);

    $codes = collect($results)->map(fn (array $r) => $r['ok'] ? 'OK' : $r['code'])->countBy()->sortKeys()->all();
    expect($codes)->toBe(['DATES_UNAVAILABLE' => 19, 'OK' => 1], json_encode($results));

    expect($db->table('inventory_occupancies')->where('unit_id', $this->setup['unit'])->where('status', 'ACTIVE')->count())->toBe(1)
        ->and($db->table('reservations')->where('unit_id', $this->setup['unit'])->count())->toBe(1)
        ->and($db->table('payments')->count())->toBe(1);
});
