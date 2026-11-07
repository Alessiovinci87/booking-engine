<?php

use App\Domain\Payments\Gateway\Fake\FakeCustomerAction;
use App\Domain\Quotes\Enums\QuoteStatus;
use App\Domain\Quotes\Outbox\SendQuoteEmail;
use App\Domain\Reservations\Enums\ReservationStatus;
use App\Domain\Reservations\Models\Reservation;
use App\Domain\Reservations\Outbox\ReservationEmail;
use App\Domain\Tenancy\Models\AuditLog;
use App\Domain\Tenancy\Models\OutboxMessage;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;

/*
 * AC8 (01), 03 sezione 4, 07 "Preventivi": il gestore crea un preventivo con sconto e lo invia (link,
 * WhatsApp, email); l'ospite lo apre e lo accetta entro la scadenza, prenota e paga; il preventivo
 * diventa ACCEPTED. Un secondo accept durante il checkout restituisce lo stesso checkout (I21).
 */

beforeEach(fn () => Carbon::setTestNow('2027-06-01 10:00:00'));
afterEach(fn () => Carbon::setTestNow());

it('pannello: crea con sconto, copia il link (DRAFT → SENT) con testo WhatsApp senza dati personali', function () {
    $fx = bookableTenant();
    signedInOwner($this, $fx['tenant']);

    $created = panelPost($this, '/api/v1/admin/quotes', [
        'unit_id' => $fx['unit'], 'check_in' => '2027-07-10', 'check_out' => '2027-07-14', 'adults' => 2,
        'discount_cents' => 4000, 'guest_name' => 'Ada Lovelace', 'guest_email' => 'Ada@Example.test', 'guest_locale' => 'en',
    ])->assertCreated()->json('data');

    expect($created['status'])->toBe('DRAFT')
        ->and($created['subtotal']['amount_cents'])->toBe(54000)
        ->and($created['discount']['amount_cents'])->toBe(4000)
        ->and($created['total']['amount_cents'])->toBe(50000)
        ->and($created['deposit']['amount_cents'])->toBe(15000)
        ->and($created['guest_email'])->toBe('ada@example.test')
        ->and($created['expires_at'])->toBe('2027-06-03T10:00:00Z')
        ->and($created['link'])->toBeNull()
        ->and(collect($created['lines'])->pluck('kind')->last())->toBe('DISCOUNT');

    $sent = panelPost($this, "/api/v1/admin/quotes/{$created['id']}/send", ['channel' => 'link'])->assertOk()->json('data');

    expect($sent['status'])->toBe('SENT')
        ->and($sent['link'])->toBe(url('/q/'.quoteToken($created['id'])))
        ->and($sent['whatsapp_text'])->toContain($sent['link'])->not->toContain('Ada')
        ->and($sent['whatsapp_url'])->toStartWith('https://wa.me/?text=')
        ->and(inTenant($fx['tenant'], fn () => OutboxMessage::query()->where('type', 'quote.sent')->count()))->toBe(0);

    // Nessun dato dell'ospite nell'audit.
    expect(inTenant($fx['tenant'], fn () => AuditLog::query()->where('subject_id', $created['id'])->get()->toJson()))
        ->not->toContain('ada@example.test')->not->toContain('Lovelace');

    $list = panelGet($this, '/api/v1/admin/quotes?status[]=SENT')->assertOk()->json('data');
    expect($list)->toHaveCount(1)->and($list[0]['id'])->toBe($created['id']);
});

it('invio per email: messaggio in outbox con solo l\'ID e email all\'ospite con il link', function () {
    $fx = bookableTenant();
    signedInOwner($this, $fx['tenant']);
    $quote = quoteFor($fx, ['draft' => true]);

    panelPost($this, "/api/v1/admin/quotes/{$quote->id}/send", ['channel' => 'email'])->assertOk()->assertJsonPath('data.status', 'SENT');

    $message = inTenant($fx['tenant'], fn () => OutboxMessage::query()->where('type', 'quote.sent')->firstOrFail());
    expect($message->payload)->toBe(['quote_id' => $quote->id]);

    Mail::fake();
    inTenant($fx['tenant'], fn () => app(SendQuoteEmail::class)->handle($message));
    Mail::assertSent(ReservationEmail::class, fn ($mail) => $mail->hasTo('ada@example.test') && $mail->link === url('/q/'.quoteToken($quote)));
});

it('ospite: legge il preventivo, accetta, paga; il preventivo diventa ACCEPTED', function () {
    $fx = bookableTenant();
    $quote = quoteFor($fx);
    $token = quoteToken($quote);

    $this->getJson("/api/v1/public/quotes/{$token}")->assertOk()
        ->assertJsonPath('status', 'SENT')
        ->assertJsonPath('total.amount_cents', 50000)
        ->assertJsonPath('discount.amount_cents', 4000)
        ->assertJsonPath('booking_mode', 'INSTANT')
        ->assertJsonPath('expires_at', '2027-06-03T10:00:00Z')
        ->assertJsonPath('check_in', '2027-07-10');

    $response = acceptQuote($this, $token)->assertCreated()->assertJsonPath('status', 'AWAITING_PAYMENT');
    $reservation = quoteReservation($fx, $response->json('reservation_code'));

    expect($reservation->channel->value)->toBe('QUOTE')
        ->and($reservation->quote_id)->toBe($quote->id)
        ->and($reservation->contract_total_cents)->toBe(50000)
        ->and($reservation->deposit_due_cents)->toBe(15000)
        ->and($response->json('access_token'))->toMatch('/^[a-f0-9]{64}$/')
        ->and(freshQuote($fx, $quote->id)->status)->toBe(QuoteStatus::IN_CHECKOUT)
        ->and(freshQuote($fx, $quote->id)->checkout_attempts)->toBe(1);

    simulateCustomer($fx, $reservation, FakeCustomerAction::PAY);

    expect(freshQuote($fx, $quote->id)->status)->toBe(QuoteStatus::ACCEPTED)
        ->and(quoteReservation($fx, $reservation->code)->status->value)->toBe('CONFIRMED');

    $this->getJson("/api/v1/public/quotes/{$token}")->assertOk()->assertJsonPath('accepted', true);
    acceptQuote($this, $token)->assertStatus(409)->assertJsonPath('error.code', 'QUOTE_ALREADY_ACCEPTED');
});

it('I21: un secondo accept durante il checkout restituisce lo stesso codice e lo stesso checkout', function () {
    $fx = bookableTenant();
    $token = quoteToken(quoteFor($fx));

    $first = acceptQuote($this, $token)->assertCreated();
    $second = acceptQuote($this, $token)->assertOk();

    expect($second->json('reservation_code'))->toBe($first->json('reservation_code'))
        ->and($second->json('checkout_url'))->toBe($first->json('checkout_url'))
        ->and($second->json('access_token'))->toBeNull()
        ->and(inTenant($fx['tenant'], fn () => Reservation::query()->count()))->toBe(1);
});

it('unità REQUEST: l\'autorizzazione porta il preventivo ad ACCEPTED (prenotazione AWAITING_APPROVAL)', function () {
    $fx = bookableTenant('REQUEST');
    $quote = quoteFor($fx);
    $code = acceptQuote($this, quoteToken($quote))->assertCreated()->json('reservation_code');

    simulateCustomer($fx, quoteReservation($fx, $code), FakeCustomerAction::PAY);

    expect(quoteReservation($fx, $code)->status->value)->toBe('AWAITING_APPROVAL')
        ->and(freshQuote($fx, $quote->id)->status)->toBe(QuoteStatus::ACCEPTED);
});

it('pagamento fallito: il preventivo torna SENT e si può riprovare; superati i tentativi → EXHAUSTED', function () {
    $fx = bookableTenant();
    $quote = quoteFor($fx, ['max_attempts' => 2]);
    $token = quoteToken($quote);

    $code = acceptQuote($this, $token)->assertCreated()->json('reservation_code');
    simulateCustomer($fx, quoteReservation($fx, $code), FakeCustomerAction::DECLINE);
    expect(freshQuote($fx, $quote->id)->status)->toBe(QuoteStatus::SENT)
        ->and(freshQuote($fx, $quote->id)->active_reservation_id)->toBeNull();

    $second = acceptQuote($this, $token)->assertCreated()->json('reservation_code');
    expect($second)->not->toBe($code);
    simulateCustomer($fx, quoteReservation($fx, $second), FakeCustomerAction::DECLINE);

    expect(freshQuote($fx, $quote->id)->status)->toBe(QuoteStatus::EXHAUSTED)
        ->and(freshQuote($fx, $quote->id)->checkout_attempts)->toBe(2);
    $this->getJson("/api/v1/public/quotes/{$token}")->assertStatus(410)->assertJsonPath('error.code', 'QUOTE_EXHAUSTED');
    acceptQuote($this, $token)->assertStatus(410)->assertJsonPath('error.code', 'QUOTE_EXHAUSTED');
});

it('ritirato: 410 QUOTE_REVOKED; durante il checkout il tentativo attivo prosegue fino al suo esito', function () {
    $fx = bookableTenant();
    signedInOwner($this, $fx['tenant']);
    $quote = quoteFor($fx);
    $token = quoteToken($quote);
    $code = acceptQuote($this, $token)->assertCreated()->json('reservation_code');

    panelPost($this, "/api/v1/admin/quotes/{$quote->id}/revoke")->assertOk()->assertJsonPath('data.status', 'REVOKED');
    $this->getJson("/api/v1/public/quotes/{$token}")->assertStatus(410)->assertJsonPath('error.code', 'QUOTE_REVOKED');
    acceptQuote($this, $token)->assertStatus(410)->assertJsonPath('error.code', 'QUOTE_REVOKED');

    simulateCustomer($fx, quoteReservation($fx, $code), FakeCustomerAction::PAY);
    expect(quoteReservation($fx, $code)->status->value)->toBe('CONFIRMED')
        ->and(freshQuote($fx, $quote->id)->status)->toBe(QuoteStatus::REVOKED);

    panelPost($this, "/api/v1/admin/quotes/{$quote->id}/revoke")->assertStatus(409)->assertJsonPath('error.code', 'ILLEGAL_TRANSITION');
});

it('link non inviato (DRAFT), token sconosciuto o preventivo di un altro tenant → 404', function () {
    $fx = bookableTenant();
    $draft = quoteFor($fx, ['draft' => true]);
    $other = bookableTenant();
    $foreign = quoteFor($other);
    signedInOwner($this, $fx['tenant']);

    $this->getJson('/api/v1/public/quotes/'.quoteToken($draft))->assertNotFound();
    acceptQuote($this, quoteToken($draft))->assertNotFound();
    $this->getJson('/api/v1/public/quotes/'.str_repeat('a', 43))->assertNotFound();
    panelGet($this, "/api/v1/admin/quotes/{$foreign->id}")->assertNotFound();
    panelPost($this, "/api/v1/admin/quotes/{$foreign->id}/revoke")->assertNotFound();
});

it('date non più disponibili all\'accettazione → 409 DATES_UNAVAILABLE, nessun tentativo consumato', function () {
    $fx = bookableTenant();
    $quote = quoteFor($fx);
    reservationIn($fx, ReservationStatus::CONFIRMED, '[2027-07-12,2027-07-16)');

    acceptQuote($this, quoteToken($quote))->assertStatus(409)->assertJsonPath('error.code', 'DATES_UNAVAILABLE');
    expect(freshQuote($fx, $quote->id)->checkout_attempts)->toBe(0)
        ->and(freshQuote($fx, $quote->id)->status)->toBe(QuoteStatus::SENT);
});

it('richiede le versioni correnti di condizioni e informativa', function () {
    $fx = bookableTenant();
    $quote = quoteFor($fx);

    acceptQuote($this, quoteToken($quote), ['accepted_privacy_version' => 'vecchia'])
        ->assertStatus(400)->assertJsonPath('error.code', 'VALIDATION_FAILED');
    expect(freshQuote($fx, $quote->id)->checkout_attempts)->toBe(0);
});
