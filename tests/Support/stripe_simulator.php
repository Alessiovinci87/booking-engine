<?php

use App\Domain\Reservations\Models\Reservation;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/*
 * Stripe simulato con stato (4.11): sopra FakeStripeHttp registra le risposte dell'API per sessioni
 * di checkout, PaymentIntent e rimborsi di un account connesso, con le regole di Stripe che il nostro
 * codice deve rispettare (idempotency key, expires_at da 30 minuti a 24 ore, expire solo su sessioni
 * aperte, capture solo da requires_capture). Le azioni dell'ospite (paga, autorizza, carta rifiutata)
 * e gli eventi firmati del webhook completano i flussi. Nessuna chiamata di rete.
 */

final class StripeSimulator
{
    public const WEBHOOK_SECRET = 'whsec_test_simulated';

    /** @var array<string, array<string, mixed>> */
    public array $sessions = [];

    /** @var array<string, array<string, mixed>> */
    public array $intents = [];

    /** @var array<string, array<string, mixed>> */
    public array $refunds = [];

    /** @var array<string, string> idempotency key → ID dell'oggetto creato */
    private array $idempotency = [];

    /** Prossimo esito dei rimborsi creati: succeeded, pending, failed. */
    public string $refundStatus = 'succeeded';

    /** Codice di rifiuto per la prossima capture (null = riuscita). */
    public ?string $declineNextCapture = null;

    /** La prossima creazione di sessione riesce su Stripe ma la risposta si perde (crash tra passo 2 e 3). */
    public bool $loseNextCreateResponse = false;

    private int $sequence = 0;

    public function __construct(public readonly FakeStripeHttp $http)
    {
        $http->on('post', '/v1/checkout/sessions', fn (array $p, array $r): array => $this->createSession($p, $r));
        $http->on('get', '/v1/checkout/sessions/(cs_[A-Za-z0-9_]+)', fn (array $p, array $r): array => $this->sessionView($this->id($r)));
        $http->on('post', '/v1/checkout/sessions/(cs_[A-Za-z0-9_]+)/expire', fn (array $p, array $r): array => $this->expire($this->id($r, -2)));
        $http->on('get', '/v1/payment_intents/(pi_[A-Za-z0-9_]+)', fn (array $p, array $r): array => $this->intentView($this->id($r)));
        $http->on('post', '/v1/payment_intents/(pi_[A-Za-z0-9_]+)/capture', fn (array $p, array $r): array => $this->capture($this->id($r, -2), (int) ($p['amount_to_capture'] ?? 0)));
        $http->on('post', '/v1/payment_intents/(pi_[A-Za-z0-9_]+)/cancel', fn (array $p, array $r): array => $this->cancel($this->id($r, -2)));
        $http->on('post', '/v1/refunds', fn (array $p, array $r): array => $this->createRefund($p, $r));
        $http->on('get', '/v1/refunds/(re_[A-Za-z0-9_]+)', fn (array $p, array $r): array => $this->refunds[$this->id($r)] ?? $this->notFound());
    }

    // --- Azioni dell'ospite e del fornitore -------------------------------------------------------

    /** L'ospite paga (capture automatica) o autorizza (capture manuale). */
    public function pay(string $sessionId): array
    {
        $session = &$this->sessions[$sessionId];
        $pi = $session['payment_intent'] ?? 'pi_test_'.(++$this->sequence);
        $manual = $session['capture_method'] === 'manual';
        $session['payment_intent'] = $pi;
        $session['status'] = 'complete';
        $this->intents[$pi] = stripeIntent($pi, $manual ? 'requires_capture' : 'succeeded', $session['amount_total'], [
            'metadata' => $session['metadata'], 'capture_method' => $session['capture_method'],
        ]);

        return $this->intents[$pi];
    }

    /** Carta rifiutata: la sessione resta aperta e l'ospite può riprovare (come Stripe Checkout). */
    public function decline(string $sessionId, string $code = 'card_declined'): array
    {
        $session = &$this->sessions[$sessionId];
        $pi = $session['payment_intent'] ?? 'pi_test_'.(++$this->sequence);
        $session['payment_intent'] = $pi;
        $this->intents[$pi] = stripeIntent($pi, 'requires_payment_method', $session['amount_total'], [
            'metadata' => $session['metadata'], 'last_payment_error' => ['type' => 'card_error', 'code' => 'card_declined', 'decline_code' => $code],
        ]);

        return $this->intents[$pi];
    }

    public function settleRefund(string $refundId, string $status): array
    {
        $this->refunds[$refundId]['status'] = $status;

        return $this->refunds[$refundId];
    }

    /** Sessione come la restituisce l'API (oggetto degli eventi checkout.session.*). */
    public function lastSessionView(string $id): array
    {
        return $this->sessionView($id);
    }

    /** Ultima sessione creata. */
    public function lastSession(): array
    {
        return $this->sessionView((string) array_key_last($this->sessions));
    }

    /**
     * Evento firmato come lo invia Stripe al webhook degli account connessi.
     *
     * @param  array<string, mixed>  $object
     * @return array{payload: string, signature: string, event: array<string, mixed>}
     */
    public function event(string $type, array $object, ?string $account, ?string $eventId = null, ?int $signedAt = null): array
    {
        $event = [
            'id' => $eventId ?? 'evt_test_'.(++$this->sequence), 'object' => 'event', 'type' => $type, 'account' => $account,
            'api_version' => '2026-10-28.endive', 'created' => now()->getTimestamp(), 'livemode' => false,
            'data' => ['object' => $object], 'pending_webhooks' => 1, 'request' => ['id' => null, 'idempotency_key' => null],
        ];
        $payload = json_encode($event, JSON_THROW_ON_ERROR);
        $timestamp = $signedAt ?? time();

        return ['payload' => $payload, 'event' => $event, 'signature' => 't='.$timestamp.',v1='.hash_hmac('sha256', $timestamp.'.'.$payload, self::WEBHOOK_SECRET)];
    }

    // --- API simulata -------------------------------------------------------------------------------

    private function createSession(array $params, array $request): array
    {
        if (($existing = $this->replay($request)) !== null) {
            return $this->sessionView($existing);
        }

        $expiresAt = (int) $params['expires_at'];
        $now = now()->getTimestamp();

        if ($expiresAt < $now + 30 * 60 || $expiresAt > $now + 24 * 3600) {
            return $this->error(400, 'invalid_request_error', 'parameter_invalid_integer');
        }

        $id = 'cs_test_'.(++$this->sequence).bin2hex(random_bytes(4));
        $this->sessions[$id] = [
            'id' => $id, 'object' => 'checkout.session', 'status' => 'open', 'url' => 'https://checkout.stripe.com/c/pay/'.$id,
            'expires_at' => $expiresAt, 'amount_total' => (int) $params['line_items'][0]['price_data']['unit_amount'],
            'currency' => $params['line_items'][0]['price_data']['currency'], 'metadata' => $params['metadata'],
            'capture_method' => $params['payment_intent_data']['capture_method'], 'payment_intent' => null,
            'success_url' => $params['success_url'], 'cancel_url' => $params['cancel_url'], 'locale' => $params['locale'],
            'customer_email' => $params['customer_email'] ?? null, 'account' => $request['account'],
        ];
        $this->remember($request, $id);

        if ($this->loseNextCreateResponse) {
            $this->loseNextCreateResponse = false;

            return $this->error(500, 'api_error', 'timeout');
        }

        return $this->sessionView($id);
    }

    private function sessionView(string $id): array
    {
        $session = $this->sessions[$id] ?? null;

        if ($session === null) {
            return $this->notFound();
        }

        if ($session['status'] === 'open' && $session['expires_at'] <= now()->getTimestamp()) {
            $session['status'] = $this->sessions[$id]['status'] = 'expired';
        }

        $pi = $session['payment_intent'];
        $session['payment_intent'] = $pi === null ? null : $this->intents[$pi];
        unset($session['account'], $session['capture_method']);

        return $session;
    }

    private function expire(string $id): array
    {
        $view = $this->sessionView($id);

        if (isset($view['error'])) {
            return $view;
        }

        if ($view['status'] !== 'open') {
            return $this->error(400, 'invalid_request_error', 'checkout_session_not_expirable');
        }

        $this->sessions[$id]['status'] = 'expired';

        return $this->sessionView($id);
    }

    private function intentView(string $id): array
    {
        return $this->intents[$id] ?? $this->notFound();
    }

    private function capture(string $id, int $amount): array
    {
        $intent = $this->intents[$id] ?? null;

        if ($intent === null) {
            return $this->notFound();
        }

        if ($this->declineNextCapture !== null) {
            $code = $this->declineNextCapture;
            $this->declineNextCapture = null;

            return $this->error(402, 'card_error', 'card_declined', $code);
        }

        if ($intent['status'] !== 'requires_capture') {
            return $this->error(400, 'invalid_request_error', 'payment_intent_unexpected_state');
        }

        $this->intents[$id]['status'] = 'succeeded';
        $this->intents[$id]['amount_received'] = $amount;

        return $this->intents[$id];
    }

    private function cancel(string $id): array
    {
        $intent = $this->intents[$id] ?? null;

        if ($intent === null) {
            return $this->notFound();
        }

        if (in_array($intent['status'], ['succeeded', 'canceled'], true)) {
            return $this->error(400, 'invalid_request_error', 'payment_intent_unexpected_state');
        }

        $this->intents[$id]['status'] = 'canceled';

        return $this->intents[$id];
    }

    private function createRefund(array $params, array $request): array
    {
        if (($existing = $this->replay($request)) !== null) {
            return $this->refunds[$existing];
        }

        $intent = $this->intents[$params['payment_intent']] ?? null;
        $refunded = array_sum(array_map(fn (array $r): int => $r['payment_intent'] === $params['payment_intent'] && $r['status'] !== 'failed' ? $r['amount'] : 0, $this->refunds));

        if ($intent === null || $intent['status'] !== 'succeeded' || $refunded + (int) $params['amount'] > $intent['amount_received']) {
            return $this->error(400, 'invalid_request_error', 'charge_already_refunded');
        }

        $id = 're_test_'.(++$this->sequence);
        $this->refunds[$id] = stripeRefund($id, $this->refundStatus, (int) $params['amount']) + ['payment_intent' => $params['payment_intent']];
        $this->refunds[$id]['metadata'] = $params['metadata'] ?? [];
        $this->remember($request, $id);

        return $this->refunds[$id];
    }

    private function replay(array $request): ?string
    {
        $key = $request['idempotency_key'] ?? null;

        return $key === null ? null : ($this->idempotency[($request['account'] ?? '').'|'.$key] ?? null);
    }

    private function remember(array $request, string $id): void
    {
        if (($request['idempotency_key'] ?? null) !== null) {
            $this->idempotency[($request['account'] ?? '').'|'.$request['idempotency_key']] = $id;
        }
    }

    private function id(array $request, int $offset = -1): string
    {
        $parts = explode('/', $request['path']);

        return $parts[count($parts) + $offset];
    }

    private function notFound(): array
    {
        return $this->error(404, 'invalid_request_error', 'resource_missing');
    }

    /** Le risposte d'errore portano lo stato HTTP nel campo _status, letto da FakeStripeHttp. */
    private function error(int $status, string $type, string $code, ?string $declineCode = null): array
    {
        return ['_status' => $status, 'error' => array_filter(['type' => $type, 'code' => $code, 'decline_code' => $declineCode, 'message' => 'Errore simulato.'])];
    }
}

function stripeSimulator(): StripeSimulator
{
    return new StripeSimulator(fakeStripe());
}

/**
 * Consegna un evento firmato al webhook come Stripe (corpo grezzo e header Stripe-Signature).
 *
 * @param  array{payload: string, signature: string}  $signed
 */
function postStripeWebhook(TestCase $test, array $signed, ?string $signature = null): TestResponse
{
    return $test->call('POST', '/webhooks/stripe', [], [], [], [
        'HTTP_STRIPE_SIGNATURE' => $signature ?? $signed['signature'],
        'CONTENT_TYPE' => 'application/json',
    ], $signed['payload']);
}

/**
 * L'ospite paga (o autorizza) su Stripe Checkout e arriva checkout.session.completed.
 */
function stripeGuestPays(TestCase $test, StripeSimulator $sim, array $fx, Reservation $reservation): Reservation
{
    $sessionId = (string) latestPayment($fx, $reservation->id)->checkout_session_id;
    $sim->pay($sessionId);
    postStripeWebhook($test, $sim->event('checkout.session.completed', $sim->lastSessionView($sessionId), $fx['account']))->assertOk();

    return reloadReservation($fx, $reservation->id);
}
