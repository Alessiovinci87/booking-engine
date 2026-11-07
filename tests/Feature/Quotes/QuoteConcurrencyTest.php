<?php

use App\Domain\Quotes\QuoteLinks;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

/*
 * AC16 e I21 (01, 02), 03 sezione 4: dieci processi PHP veri accettano insieme lo stesso preventivo.
 * La riga del preventivo bloccata serializza gli accept: nasce un solo tentativo (una prenotazione, un
 * pagamento, checkout_attempts = 1) e tutti ricevono lo stesso codice e lo stesso checkout. I dati di
 * partenza sono confermati nel database (tests/Support/concurrency.php) e cancellati alla fine.
 */

beforeEach(function () {
    $this->setup = committedTenantWithUnit(['booking_mode' => 'INSTANT', 'min_nights' => 1]);
});

afterEach(function () {
    deleteCommittedTenant($this->setup['tenant']);
});

it('10 accept paralleli dello stesso preventivo: un solo tentativo, stesso checkout per tutti', function () {
    $tenant = $this->setup['tenant'];
    $db = DB::connection(committedConnection('pgsql', $tenant));
    $now = now();
    $policy = uuid7();
    $quote = uuid7();
    $token = QuoteLinks::newToken();

    $db->table('rate_plans')->insert(['id' => uuid7(), 'tenant_id' => $tenant, 'unit_id' => $this->setup['unit'], 'name' => 'Base', 'base_nightly_cents' => 12000, 'currency' => 'EUR', 'active' => true, 'created_at' => $now, 'updated_at' => $now]);
    $db->table('cancellation_policies')->insert(['id' => $policy, 'tenant_id' => $tenant, 'property_id' => $this->setup['property'], 'code' => 'FLEXIBLE_7D', 'created_at' => $now, 'updated_at' => $now]);
    $db->table('properties')->where('id', $this->setup['property'])->update(['status' => 'PUBLISHED', 'default_cancellation_policy_id' => $policy]);
    $db->table('stripe_accounts')->insert(['id' => uuid7(), 'tenant_id' => $tenant, 'stripe_account_id' => 'acct_conc_'.substr(uuid7(), -10), 'charges_enabled' => true, 'created_at' => $now, 'updated_at' => $now]);
    $db->table('quotes')->insert([
        'id' => $quote, 'tenant_id' => $tenant, 'unit_id' => $this->setup['unit'], 'stay' => '[2027-08-10,2027-08-13)', 'adults' => 2,
        'computed_total_cents' => 36000, 'discount_cents' => 6000, 'final_total_cents' => 30000,
        'price_lines' => json_encode([
            ['kind' => 'NIGHT', 'date' => '2027-08-10', 'quantity' => 1, 'unit_amount_cents' => 12000, 'amount_cents' => 12000, 'season_id' => null],
            ['kind' => 'NIGHT', 'date' => '2027-08-11', 'quantity' => 1, 'unit_amount_cents' => 12000, 'amount_cents' => 12000, 'season_id' => null],
            ['kind' => 'NIGHT', 'date' => '2027-08-12', 'quantity' => 1, 'unit_amount_cents' => 12000, 'amount_cents' => 12000, 'season_id' => null],
            ['kind' => 'DISCOUNT', 'date' => null, 'quantity' => 1, 'unit_amount_cents' => -6000, 'amount_cents' => -6000, 'season_id' => null],
        ]),
        'booking_mode_snapshot' => 'INSTANT', 'payment_mode_snapshot' => 'FULL', 'expires_at' => $now->copy()->addDay(),
        'public_token_encrypted' => Crypt::encryptString($token), 'public_token_hash' => QuoteLinks::hash($token), 'status' => 'SENT', 'created_at' => $now, 'updated_at' => $now,
    ]);

    $results = runConcurrently('accept-quote.php', [$tenant, $token], 10);

    $outcomes = collect($results)->map(fn (array $r) => $r['ok'] ? ($r['repeat'] ? 'REPEAT' : 'NEW') : $r['code'])->countBy()->sortKeys()->all();
    expect($outcomes)->toBe(['NEW' => 1, 'REPEAT' => 9], json_encode($results));

    expect(collect($results)->pluck('code')->unique()->count())->toBe(1)
        ->and(collect($results)->pluck('checkout_url')->unique()->count())->toBe(1)
        ->and($db->table('reservations')->where('quote_id', $quote)->count())->toBe(1)
        ->and($db->table('payments')->count())->toBe(1)
        ->and($db->table('payments')->value('amount_cents'))->toBe(30000)
        ->and($db->table('inventory_occupancies')->where('unit_id', $this->setup['unit'])->where('status', 'ACTIVE')->count())->toBe(1)
        ->and((array) $db->table('quotes')->where('id', $quote)->first(['status', 'checkout_attempts']))->toBe(['status' => 'IN_CHECKOUT', 'checkout_attempts' => 1]);
});
