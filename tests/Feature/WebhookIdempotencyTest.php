<?php

use App\Domain\Payments\Enums\PaymentStatus;
use App\Domain\Payments\Models\PaymentEvent;
use App\Domain\Payments\Models\WebhookEvent;
use App\Domain\Reservations\Enums\ReservationStatus;
use App\Domain\Tenancy\Models\OutboxMessage;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/*
 * I3, AC10 (02, 05 "Webhook"): lo stesso webhook non produce due pagamenti né due conferme; eventi
 * fuori ordine, firma errata, account sconosciuto e tenant incoerente. Stripe simulato: il webhook
 * rilegge sempre l'oggetto dall'API (passo 6) e il contenuto dell'evento non basta a confermare.
 */

beforeEach(function () {
    Carbon::setTestNow('2027-06-01 10:00:00');
    $this->sim = stripeSimulator();
    $this->fx = bookableTenant('INSTANT');
    $this->reservation = startCheckout($this->fx);
    $this->payment = latestPayment($this->fx, $this->reservation->id);
    $this->sessionId = (string) $this->payment->checkout_session_id;
});
afterEach(function () {
    resetStripeHttp();
    Carbon::setTestNow();
});

function completedEvent(StripeSimulator $sim, string $sessionId, ?string $account, ?string $eventId = null): array
{
    return $sim->event('checkout.session.completed', $sim->lastSessionView($sessionId), $account, $eventId);
}

it('checkout.session.completed conferma la prenotazione dopo la rilettura via API', function () {
    $this->sim->pay($this->sessionId);

    postStripeWebhook($this, completedEvent($this->sim, $this->sessionId, $this->fx['account']))->assertOk()->assertJsonPath('status', 'RECEIVED');

    expect(reloadReservation($this->fx, $this->reservation->id)->status)->toBe(ReservationStatus::CONFIRMED)
        ->and(latestPayment($this->fx, $this->reservation->id)->status)->toBe(PaymentStatus::SUCCEEDED)
        ->and(WebhookEvent::query()->sole()->status->value)->toBe('PROCESSED')
        ->and($this->sim->http->requestsTo('get', '/v1/checkout/sessions/'.$this->sessionId)[0]['account'])->toBe($this->fx['account']);
});

it('lo stesso evento consegnato due volte produce una sola conferma e un solo pagamento (I3)', function () {
    $this->sim->pay($this->sessionId);
    $event = completedEvent($this->sim, $this->sessionId, $this->fx['account'], 'evt_dup_1');

    postStripeWebhook($this, $event)->assertOk();
    postStripeWebhook($this, $event)->assertOk()->assertJsonPath('status', 'PROCESSED');

    $succeeded = inTenant($this->fx['tenant'], fn () => PaymentEvent::query()->where('type', 'succeeded')->count());
    $confirmations = inTenant($this->fx['tenant'], fn () => OutboxMessage::query()->where('type', 'reservation.confirmed')->count());
    expect(WebhookEvent::query()->count())->toBe(1)
        ->and($succeeded)->toBe(1)
        ->and($confirmations)->toBe(1)
        ->and($this->sim->http->requestsTo('get', '/v1/checkout/sessions/.+'))->toHaveCount(1);
});

it('eventi diversi per lo stesso pagamento (fuori ordine) non confermano due volte', function () {
    $intent = $this->sim->pay($this->sessionId);

    // payment_intent.succeeded prima di checkout.session.completed: il PaymentIntent non è ancora
    // collegato al pagamento, lo trova dai metadata.
    postStripeWebhook($this, $this->sim->event('payment_intent.succeeded', $intent, $this->fx['account']))->assertOk();
    postStripeWebhook($this, completedEvent($this->sim, $this->sessionId, $this->fx['account']))->assertOk();
    postStripeWebhook($this, $this->sim->event('checkout.session.expired', $this->sim->lastSessionView($this->sessionId), $this->fx['account']))->assertOk();

    expect(reloadReservation($this->fx, $this->reservation->id)->status)->toBe(ReservationStatus::CONFIRMED)
        ->and(inTenant($this->fx['tenant'], fn () => PaymentEvent::query()->where('type', 'succeeded')->count()))->toBe(1)
        ->and(occupancyViolations($this->fx['tenant']))->toBe([]);
});

it('un evento con contenuto falsificato non conferma: conta solo ciò che si rilegge', function () {
    // La sessione su Stripe è ancora aperta, ma l'evento dice "complete".
    $forged = $this->sim->lastSessionView($this->sessionId);
    $forged['status'] = 'complete';

    postStripeWebhook($this, $this->sim->event('checkout.session.completed', $forged, $this->fx['account']))->assertOk();

    expect(reloadReservation($this->fx, $this->reservation->id)->status)->toBe(ReservationStatus::AWAITING_PAYMENT);
});

it('firma errata, assente o scaduta → 400 senza registrare nulla', function () {
    $this->sim->pay($this->sessionId);
    $event = completedEvent($this->sim, $this->sessionId, $this->fx['account']);

    postStripeWebhook($this, $event, 't='.time().',v1='.str_repeat('0', 64))->assertStatus(400);
    postStripeWebhook($this, $event, '')->assertStatus(400);
    postStripeWebhook($this, $this->sim->event('checkout.session.completed', [], $this->fx['account'], signedAt: time() - 3600))->assertStatus(400);

    expect(WebhookEvent::query()->count())->toBe(0)
        ->and(reloadReservation($this->fx, $this->reservation->id)->status)->toBe(ReservationStatus::AWAITING_PAYMENT);
});

it('account sconosciuto o evento della piattaforma → IGNORED', function () {
    $this->sim->pay($this->sessionId);

    postStripeWebhook($this, completedEvent($this->sim, $this->sessionId, 'acct_sconosciuto'))->assertOk()->assertJsonPath('status', 'IGNORED');
    postStripeWebhook($this, completedEvent($this->sim, $this->sessionId, null))->assertOk()->assertJsonPath('status', 'IGNORED');

    expect(reloadReservation($this->fx, $this->reservation->id)->status)->toBe(ReservationStatus::AWAITING_PAYMENT)
        ->and(WebhookEvent::query()->where('status', 'IGNORED')->count())->toBe(2);
});

it('metadata.tenant_id di un altro tenant → FAILED con allerta, nessun effetto', function () {
    $other = bookableTenant('INSTANT');
    $this->sim->pay($this->sessionId);

    // Evento dall'account del primo tenant con i metadata del secondo.
    $object = $this->sim->lastSessionView($this->sessionId);
    $object['metadata']['tenant_id'] = $other['tenant'];

    postStripeWebhook($this, $this->sim->event('checkout.session.completed', $object, $this->fx['account']))->assertOk()->assertJsonPath('status', 'FAILED');

    expect(reloadReservation($this->fx, $this->reservation->id)->status)->toBe(ReservationStatus::AWAITING_PAYMENT)
        ->and(WebhookEvent::query()->sole()->last_error)->toBe('tenant_mismatch');
});

it('sessioni senza i nostri metadata (link di pagamento del gestore) → IGNORED', function () {
    $object = $this->sim->lastSessionView($this->sessionId);
    $object['metadata'] = [];

    postStripeWebhook($this, $this->sim->event('checkout.session.completed', $object, $this->fx['account']))->assertOk()->assertJsonPath('status', 'IGNORED');
});

it('webhook di una sessione non salvata (crash tra passo 2 e 3): la adotta e conferma', function () {
    // Il passo 3 non ha salvato la sessione: la rimuoviamo dal pagamento come se non fosse mai arrivata.
    inTenant($this->fx['tenant'], fn () => DB::table('payments')->where('id', $this->payment->id)->update(['checkout_session_id' => null, 'checkout_expires_at' => null]));
    $this->sim->pay($this->sessionId);

    postStripeWebhook($this, completedEvent($this->sim, $this->sessionId, $this->fx['account']))->assertOk();

    $payment = latestPayment($this->fx, $this->reservation->id);
    expect($payment->checkout_session_id)->toBe($this->sessionId)
        ->and($payment->status)->toBe(PaymentStatus::SUCCEEDED)
        ->and(reloadReservation($this->fx, $this->reservation->id)->status)->toBe(ReservationStatus::CONFIRMED);
});

it('Stripe non risponde durante l\'elaborazione: FAILED e nuovo tentativo alla consegna successiva', function () {
    $this->sim->pay($this->sessionId);
    $event = completedEvent($this->sim, $this->sessionId, $this->fx['account'], 'evt_retry_1');
    $this->sim->http->fail('get', '/v1/checkout/sessions/.+', 500, 'api_error', times: 1);

    // In coda sync l'eccezione del job risale: il ricevitore ha già registrato l'evento.
    try {
        postStripeWebhook($this, $event);
    } catch (Throwable) {
    }

    expect(WebhookEvent::query()->sole()->status->value)->toBe('FAILED');

    postStripeWebhook($this, $event)->assertOk();
    expect(WebhookEvent::query()->sole()->status->value)->toBe('PROCESSED')
        ->and(reloadReservation($this->fx, $this->reservation->id)->status)->toBe(ReservationStatus::CONFIRMED);
});

it('account.updated aggiorna lo stato dell\'account rileggendolo da Stripe', function () {
    inTenant($this->fx['tenant'], fn () => DB::table('stripe_accounts')->update(['charges_enabled' => false]));
    $this->sim->http->on('get', '/v2/core/accounts/'.$this->fx['account'], stripeV2Account($this->fx['account']));

    postStripeWebhook($this, $this->sim->event('account.updated', ['id' => $this->fx['account'], 'object' => 'account', 'charges_enabled' => false, 'metadata' => ['tenant_id' => $this->fx['tenant']]], $this->fx['account']))
        ->assertOk();

    expect(inTenant($this->fx['tenant'], fn () => DB::table('stripe_accounts')->value('charges_enabled')))->toBeTrue();
});

it('una contestazione registra un PaymentEvent e avvisa il gestore, senza cambiare gli stati', function () {
    $intent = $this->sim->pay($this->sessionId);
    postStripeWebhook($this, completedEvent($this->sim, $this->sessionId, $this->fx['account']))->assertOk();
    $dispute = ['id' => 'dp_test_1', 'object' => 'dispute', 'payment_intent' => $intent['id'], 'charge' => 'ch_x', 'amount' => 14400, 'status' => 'needs_response', 'reason' => 'fraudulent'];
    $this->sim->http->on('get', '/v1/disputes/dp_test_1', $dispute);

    postStripeWebhook($this, $this->sim->event('charge.dispute.created', $dispute, $this->fx['account']))->assertOk();
    postStripeWebhook($this, $this->sim->event('charge.dispute.created', $dispute, $this->fx['account']))->assertOk();

    expect(inTenant($this->fx['tenant'], fn () => PaymentEvent::query()->where('type', 'dispute_created')->count()))->toBe(1)
        ->and(inTenant($this->fx['tenant'], fn () => OutboxMessage::query()->where('type', 'reservation.dispute_opened')->count()))->toBe(1)
        ->and(reloadReservation($this->fx, $this->reservation->id)->status)->toBe(ReservationStatus::CONFIRMED)
        ->and(latestPayment($this->fx, $this->reservation->id)->status)->toBe(PaymentStatus::SUCCEEDED);
});
