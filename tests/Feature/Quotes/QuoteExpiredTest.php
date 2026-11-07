<?php

use App\Domain\Payments\CheckoutService;
use App\Domain\Payments\Gateway\Fake\FakeCustomerAction;
use App\Domain\Quotes\Enums\QuoteStatus;
use App\Domain\Quotes\Jobs\DispatchQuoteExpiryJob;
use App\Domain\Quotes\Jobs\ExpireQuotesJob;
use App\Domain\Quotes\QuoteLinks;
use App\Domain\Quotes\QuoteService;
use App\Domain\Reservations\Models\Reservation;
use App\Domain\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

/*
 * AC8 e I8 (01, 02), 03 sezione 4: un preventivo scaduto non è pagabile e non avvia un nuovo checkout
 * (410 QUOTE_EXPIRED); la scadenza si verifica all'avvio del checkout, quindi un checkout avviato prima
 * può completarsi; il job porta DRAFT e SENT scaduti a EXPIRED.
 */

beforeEach(fn () => Carbon::setTestNow('2027-06-01 10:00:00'));
afterEach(fn () => Carbon::setTestNow());

it('scadenza predefinita 48 h; dopo: GET 410, accept 410 e stato EXPIRED, nessuna prenotazione', function () {
    $fx = bookableTenant();
    $quote = quoteFor($fx);
    $token = quoteToken($quote);

    expect($quote->expires_at->toIso8601ZuluString())->toBe('2027-06-03T10:00:00Z');

    Carbon::setTestNow('2027-06-03 10:00:00');

    $this->getJson("/api/v1/public/quotes/{$token}")->assertStatus(410)->assertJsonPath('error.code', 'QUOTE_EXPIRED');
    acceptQuote($this, $token)->assertStatus(410)->assertJsonPath('error.code', 'QUOTE_EXPIRED');

    expect(freshQuote($fx, $quote->id)->status)->toBe(QuoteStatus::EXPIRED)
        ->and(inTenant($fx['tenant'], fn () => Reservation::query()->count()))->toBe(0);
});

it('scadenza personalizzata: un secondo prima è ancora accettabile', function () {
    $fx = bookableTenant();
    $quote = quoteFor($fx, ['expires_at' => CarbonImmutable::parse('2027-06-01 12:00:00')]);

    Carbon::setTestNow('2027-06-01 11:59:59');
    acceptQuote($this, quoteToken($quote))->assertCreated();
});

it('un checkout avviato prima della scadenza si completa anche dopo: ACCEPTED', function () {
    $fx = bookableTenant();
    $quote = quoteFor($fx, ['expires_at' => CarbonImmutable::parse('2027-06-01 10:10:00')]);
    $code = acceptQuote($this, quoteToken($quote))->assertCreated()->json('reservation_code');

    Carbon::setTestNow('2027-06-01 10:20:00');
    simulateCustomer($fx, quoteReservation($fx, $code), FakeCustomerAction::PAY);

    expect(freshQuote($fx, $quote->id)->status)->toBe(QuoteStatus::ACCEPTED);
});

it('tentativo fallito dopo la scadenza: il preventivo diventa EXPIRED, non SENT', function () {
    $fx = bookableTenant();
    $quote = quoteFor($fx, ['expires_at' => CarbonImmutable::parse('2027-06-01 10:10:00')]);
    $code = acceptQuote($this, quoteToken($quote))->assertCreated()->json('reservation_code');

    Carbon::setTestNow('2027-06-01 10:45:00');
    app(CheckoutService::class)->releaseExpired($fx['tenant'], quoteReservation($fx, $code)->id);

    expect(quoteReservation($fx, $code)->status->value)->toBe('EXPIRED')
        ->and(freshQuote($fx, $quote->id)->status)->toBe(QuoteStatus::EXPIRED)
        ->and(freshQuote($fx, $quote->id)->active_reservation_id)->toBeNull();
    acceptQuote($this, quoteToken($quote))->assertStatus(410)->assertJsonPath('error.code', 'QUOTE_EXPIRED');
});

it('il job porta a EXPIRED i DRAFT e SENT scaduti e lascia gli altri', function () {
    $fx = bookableTenant();
    $draft = quoteFor($fx, ['draft' => true, 'stay' => '[2027-07-20,2027-07-24)']);
    $sent = quoteFor($fx, ['stay' => '[2027-07-25,2027-07-29)']);
    $later = quoteFor($fx, ['stay' => '[2027-07-01,2027-07-05)', 'expires_at' => CarbonImmutable::parse('2027-06-10 10:00:00')]);

    Carbon::setTestNow('2027-06-04 10:00:00');
    (new ExpireQuotesJob($fx['tenant']))->handle(app(TenantContext::class), app(QuoteService::class));

    expect(freshQuote($fx, $draft->id)->status)->toBe(QuoteStatus::EXPIRED)
        ->and(freshQuote($fx, $sent->id)->status)->toBe(QuoteStatus::EXPIRED)
        ->and(freshQuote($fx, $later->id)->status)->toBe(QuoteStatus::SENT);
});

it('il pannello non invia un preventivo scaduto', function () {
    $fx = bookableTenant();
    $quote = quoteFor($fx, ['draft' => true]);
    signedInOwner($this, $fx['tenant']);
    inTenant($fx['tenant'], fn () => DB::table('quotes')->where('id', $quote->id)->update(['expires_at' => now()->subMinute()]));

    panelPost($this, "/api/v1/admin/quotes/{$quote->id}/send", ['channel' => 'link'])->assertStatus(410)->assertJsonPath('error.code', 'QUOTE_EXPIRED');
    expect(panelGet($this, "/api/v1/admin/quotes/{$quote->id}")->json('data.expired'))->toBeTrue();
});

it('il dispatcher (app_admin_ops) accoda un job per tenant con preventivi scaduti', function () {
    Queue::fake();
    $fx = committedTenantWithUnit();
    $db = DB::connection(committedConnection('pgsql_migrator', $fx['tenant']));
    $id = uuid7();

    try {
        $db->table('quotes')->insert([
            'id' => $id, 'tenant_id' => $fx['tenant'], 'unit_id' => $fx['unit'], 'stay' => '[2027-07-10,2027-07-14)', 'adults' => 2,
            'computed_total_cents' => 1000, 'final_total_cents' => 1000, 'price_lines' => '[]', 'booking_mode_snapshot' => 'INSTANT',
            'payment_mode_snapshot' => 'FULL', 'expires_at' => now()->subMinute(), 'public_token_encrypted' => Crypt::encryptString($token = QuoteLinks::newToken()), 'public_token_hash' => QuoteLinks::hash($token),
            'status' => 'SENT', 'created_at' => now(), 'updated_at' => now(),
        ]);

        (new DispatchQuoteExpiryJob)->handle(app('db'));

        Queue::assertPushed(ExpireQuotesJob::class, fn (ExpireQuotesJob $job) => $job->tenantId === $fx['tenant']);
    } finally {
        deleteCommittedTenant($fx['tenant']);
    }
});
