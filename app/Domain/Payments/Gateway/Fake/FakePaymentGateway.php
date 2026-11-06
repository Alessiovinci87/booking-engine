<?php

namespace App\Domain\Payments\Gateway\Fake;

use App\Domain\Payments\Enums\CaptureMethod;
use App\Domain\Payments\Gateway\CaptureResult;
use App\Domain\Payments\Gateway\CheckoutSession;
use App\Domain\Payments\Gateway\CheckoutSessionRequest;
use App\Domain\Payments\Gateway\CheckoutSessionState;
use App\Domain\Payments\Gateway\CheckoutSessionStatus;
use App\Domain\Payments\Gateway\Exceptions\FakeGatewayNotAllowed;
use App\Domain\Payments\Gateway\Exceptions\PaymentDeclined;
use App\Domain\Payments\Gateway\Exceptions\PaymentProviderUnavailable;
use App\Domain\Payments\Gateway\Exceptions\SessionAlreadyCompleted;
use App\Domain\Payments\Gateway\PaymentGateway;
use App\Domain\Payments\Gateway\PaymentIntentStatus;
use App\Domain\Payments\Gateway\RefundResult;
use App\Domain\Payments\Gateway\RefundResultStatus;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Cache\Repository;
use LogicException;

/**
 * Gateway di pagamento finto, deterministico (3.3): per i test e per il checkout simulato delle demo
 * locali. Esiste solo con APP_ENV local o testing: in ogni altro ambiente il costruttore fallisce.
 *
 * Lo stato (sessioni, PaymentIntent, rimborsi, chiavi di idempotenza) sta nella cache dell'applicazione,
 * così la pagina "Pagamento simulato" e il server vedono le stesse sessioni anche in richieste diverse.
 * Gli identificativi derivano dall'idempotency key: la stessa chiave restituisce la stessa sessione,
 * come in Stripe (05, recupero tramite idempotency key).
 *
 * Esiti programmabili nei test con failNext() (timeout, timeout dopo l'effetto, rifiuto) e
 * simulateCustomer() (pagamento riuscito o autorizzato secondo capture_method, rifiutato, abbandono).
 */
final class FakePaymentGateway implements PaymentGateway
{
    /** @var list<string> */
    public const ALLOWED_ENVIRONMENTS = ['local', 'testing'];

    private const PREFIX = 'fake-payments:';

    private const TTL_SECONDS = 7 * 24 * 3600;

    /** Margine di Stripe tra la capture_before della Charge e l'autorizzazione: 7 giorni. */
    private const AUTHORIZATION_DAYS = 7;

    public function __construct(
        private readonly Repository $cache,
        string $environment,
        private readonly string $checkoutBaseUrl,
    ) {
        if (! self::allowedIn($environment)) {
            throw FakeGatewayNotAllowed::inEnvironment($environment);
        }
    }

    public static function allowedIn(string $environment): bool
    {
        return in_array($environment, self::ALLOWED_ENVIRONMENTS, true);
    }

    public function createCheckoutSession(CheckoutSessionRequest $request): CheckoutSession
    {
        $failure = $this->takeFailure(FakeOperation::CREATE);
        $this->record(FakeOperation::CREATE, $request->idempotencyKey);

        if ($failure === FakeFailure::TIMEOUT) {
            throw PaymentProviderUnavailable::make('createCheckoutSession');
        }

        $existing = $this->cache->get($this->key('idem:create:'.$request->idempotencyKey));

        if (is_string($existing)) {
            $session = $this->load($existing);
        } else {
            $id = 'cs_fake_'.$this->digest($request->idempotencyKey);
            $session = [
                'id' => $id,
                'account_id' => $request->accountId,
                'status' => CheckoutSessionStatus::OPEN->value,
                // Stripe restituisce un timestamp al secondo.
                'expires_at' => $request->expiresAt->startOfSecond()->toIso8601String(),
                'amount_cents' => $request->amountCents,
                'currency' => $request->currency,
                'capture_method' => $request->captureMethod->value,
                'description' => $request->description,
                'success_url' => str_replace('{CHECKOUT_SESSION_ID}', $id, $request->successUrl),
                'cancel_url' => str_replace('{CHECKOUT_SESSION_ID}', $id, $request->cancelUrl),
                'locale' => $request->locale->value,
                'metadata' => $request->metadata,
                'payment_intent_id' => null,
                'payment_intent_status' => null,
                'payment_failed' => false,
                'failure_code' => null,
                'capture_before' => null,
                'captured_cents' => 0,
                'refunded_cents' => 0,
            ];
            $this->save($session);
            $this->cache->put($this->key('idem:create:'.$request->idempotencyKey), $id, self::TTL_SECONDS);
        }

        if ($failure === FakeFailure::TIMEOUT_AFTER_EFFECT) {
            throw PaymentProviderUnavailable::make('createCheckoutSession');
        }

        return new CheckoutSession($session['id'], $this->checkoutUrl($session['id']), CarbonImmutable::parse($session['expires_at']));
    }

    public function retrieveSession(string $accountId, string $sessionId): CheckoutSessionState
    {
        $failure = $this->takeFailure(FakeOperation::RETRIEVE);
        $this->record(FakeOperation::RETRIEVE, null);

        if ($failure !== null) {
            throw PaymentProviderUnavailable::make('retrieveSession');
        }

        return $this->state($this->loadFor($accountId, $sessionId));
    }

    public function expireSession(string $accountId, string $sessionId, string $idempotencyKey): CheckoutSessionState
    {
        $failure = $this->takeFailure(FakeOperation::EXPIRE);
        $this->record(FakeOperation::EXPIRE, $idempotencyKey);

        if ($failure === FakeFailure::TIMEOUT) {
            throw PaymentProviderUnavailable::make('expireSession');
        }

        $session = $this->loadFor($accountId, $sessionId);

        if ($session['status'] === CheckoutSessionStatus::COMPLETE->value) {
            throw new SessionAlreadyCompleted($sessionId);
        }

        $session['status'] = CheckoutSessionStatus::EXPIRED->value;
        $this->save($session);

        if ($failure === FakeFailure::TIMEOUT_AFTER_EFFECT) {
            throw PaymentProviderUnavailable::make('expireSession');
        }

        return $this->state($session);
    }

    public function capture(string $accountId, string $paymentIntentId, int $amountCents, string $idempotencyKey): CaptureResult
    {
        $failure = $this->takeFailure(FakeOperation::CAPTURE);
        $this->record(FakeOperation::CAPTURE, $idempotencyKey);

        if ($failure === FakeFailure::TIMEOUT) {
            throw PaymentProviderUnavailable::make('capture');
        }

        $session = $this->loadByPaymentIntent($accountId, $paymentIntentId);

        if ($session['payment_intent_status'] === PaymentIntentStatus::SUCCEEDED->value) {
            return new CaptureResult($paymentIntentId, $session['captured_cents']);
        }

        if ($failure === FakeFailure::DECLINED || $session['payment_intent_status'] !== PaymentIntentStatus::REQUIRES_CAPTURE->value) {
            throw new PaymentDeclined($failure === FakeFailure::DECLINED ? 'card_declined' : 'payment_intent_unexpected_state');
        }

        $session['payment_intent_status'] = PaymentIntentStatus::SUCCEEDED->value;
        $session['captured_cents'] = $amountCents;
        $this->save($session);

        if ($failure === FakeFailure::TIMEOUT_AFTER_EFFECT) {
            throw PaymentProviderUnavailable::make('capture');
        }

        return new CaptureResult($paymentIntentId, $amountCents);
    }

    public function cancelAuthorization(string $accountId, string $paymentIntentId, string $idempotencyKey): void
    {
        $failure = $this->takeFailure(FakeOperation::CANCEL);
        $this->record(FakeOperation::CANCEL, $idempotencyKey);

        if ($failure === FakeFailure::TIMEOUT) {
            throw PaymentProviderUnavailable::make('cancelAuthorization');
        }

        $session = $this->loadByPaymentIntent($accountId, $paymentIntentId);

        if ($session['payment_intent_status'] === PaymentIntentStatus::SUCCEEDED->value) {
            throw new PaymentDeclined('payment_intent_unexpected_state');
        }

        $session['payment_intent_status'] = PaymentIntentStatus::CANCELED->value;
        $this->save($session);

        if ($failure === FakeFailure::TIMEOUT_AFTER_EFFECT) {
            throw PaymentProviderUnavailable::make('cancelAuthorization');
        }
    }

    public function refund(string $accountId, string $paymentIntentId, int $amountCents, string $idempotencyKey): RefundResult
    {
        $failure = $this->takeFailure(FakeOperation::REFUND);
        $this->record(FakeOperation::REFUND, $idempotencyKey);

        if ($failure === FakeFailure::TIMEOUT) {
            throw PaymentProviderUnavailable::make('refund');
        }

        $refundId = 're_fake_'.$this->digest($idempotencyKey);
        $previous = $this->cache->get($this->key('idem:refund:'.$idempotencyKey));

        if (is_string($previous)) {
            return new RefundResult($refundId, RefundResultStatus::from($previous));
        }

        $session = $this->loadByPaymentIntent($accountId, $paymentIntentId);

        if ($failure === FakeFailure::DECLINED) {
            throw new PaymentDeclined('refund_failed');
        }

        if ($amountCents < 1 || $session['captured_cents'] < $session['refunded_cents'] + $amountCents) {
            throw new PaymentDeclined('amount_too_large');
        }

        $status = $failure === FakeFailure::PENDING ? RefundResultStatus::PENDING : RefundResultStatus::SUCCEEDED;
        $session['refunded_cents'] += $amountCents;
        $this->save($session);
        $this->cache->put($this->key('idem:refund:'.$idempotencyKey), $status->value, self::TTL_SECONDS);

        if ($failure === FakeFailure::TIMEOUT_AFTER_EFFECT) {
            throw PaymentProviderUnavailable::make('refund');
        }

        return new RefundResult($refundId, $status);
    }

    /**
     * Azione dell'ospite sulla pagina di checkout: PAY porta il PaymentIntent a `succeeded` con capture
     * automatica o a `requires_capture` con capture manuale; DECLINE registra un pagamento rifiutato;
     * ABANDON non cambia nulla (la sessione scade da sola o la chiude il job di rilascio).
     */
    public function simulateCustomer(string $sessionId, FakeCustomerAction $action): CheckoutSessionState
    {
        $session = $this->load($sessionId);

        if ($action === FakeCustomerAction::ABANDON || $session['status'] !== CheckoutSessionStatus::OPEN->value || $this->isPastExpiry($session)) {
            return $this->state($session);
        }

        $session['payment_intent_id'] ??= 'pi_fake_'.$this->digest($sessionId);
        $this->cache->put($this->key('pi:'.$session['payment_intent_id']), $sessionId, self::TTL_SECONDS);

        if ($action === FakeCustomerAction::DECLINE) {
            $session['payment_intent_status'] = PaymentIntentStatus::REQUIRES_PAYMENT_METHOD->value;
            $session['payment_failed'] = true;
            $session['failure_code'] = 'card_declined';
        } else {
            $manual = $session['capture_method'] === CaptureMethod::MANUAL->value;
            $session['status'] = CheckoutSessionStatus::COMPLETE->value;
            $session['payment_failed'] = false;
            $session['failure_code'] = null;
            $session['payment_intent_status'] = $manual ? PaymentIntentStatus::REQUIRES_CAPTURE->value : PaymentIntentStatus::SUCCEEDED->value;
            $session['captured_cents'] = $manual ? 0 : $session['amount_cents'];
            $session['capture_before'] = $manual ? CarbonImmutable::now()->addDays(self::AUTHORIZATION_DAYS)->startOfSecond()->toIso8601String() : null;
        }

        $this->save($session);

        return $this->state($session);
    }

    /** Programma un errore per la prossima chiamata dell'operazione (coda per operazione). */
    public function failNext(FakeOperation $operation, FakeFailure $failure): void
    {
        $queue = $this->failures($operation);
        $queue[] = $failure->value;
        $this->cache->put($this->key('failures:'.$operation->value), $queue, self::TTL_SECONDS);
    }

    /**
     * Chiamate ricevute, in ordine, con l'idempotency key usata.
     *
     * @return list<array{operation: string, idempotency_key: string|null}>
     */
    public function calls(?FakeOperation $operation = null): array
    {
        /** @var list<array{operation: string, idempotency_key: string|null}> $calls */
        $calls = $this->cache->get($this->key('calls'), []);

        return $operation === null ? $calls : array_values(array_filter($calls, fn (array $call): bool => $call['operation'] === $operation->value));
    }

    /**
     * Dati della sessione per la pagina "Pagamento simulato", senza i metadata.
     *
     * @return array{id: string, status: string, amount_cents: int, currency: string, description: string, locale: string, success_url: string, cancel_url: string, expired: bool}|null
     */
    public function checkoutPage(string $sessionId): ?array
    {
        $session = $this->cache->get($this->key('session:'.$sessionId));

        if (! is_array($session)) {
            return null;
        }

        /** @var array<string, mixed> $session */
        return [
            'id' => (string) $session['id'],
            'status' => (string) $session['status'],
            'amount_cents' => (int) $session['amount_cents'],
            'currency' => (string) $session['currency'],
            'description' => (string) $session['description'],
            'locale' => (string) $session['locale'],
            'success_url' => (string) $session['success_url'],
            'cancel_url' => (string) $session['cancel_url'],
            'expired' => $this->state($this->load($sessionId))->status === CheckoutSessionStatus::EXPIRED,
        ];
    }

    /** Account del fornitore (Stripe-Account) della sessione: serve a chi riceve l'evento simulato. */
    public function accountOf(string $sessionId): string
    {
        return $this->load($sessionId)['account_id'];
    }

    private function checkoutUrl(string $sessionId): string
    {
        return rtrim($this->checkoutBaseUrl, '/').'/_simulated-checkout/'.$sessionId;
    }

    private function takeFailure(FakeOperation $operation): ?FakeFailure
    {
        $queue = $this->failures($operation);

        if ($queue === []) {
            return null;
        }

        $next = array_shift($queue);
        $this->cache->put($this->key('failures:'.$operation->value), $queue, self::TTL_SECONDS);

        return FakeFailure::from($next);
    }

    /** @return list<string> */
    private function failures(FakeOperation $operation): array
    {
        /** @var list<string> $queue */
        $queue = $this->cache->get($this->key('failures:'.$operation->value), []);

        return $queue;
    }

    private function record(FakeOperation $operation, ?string $idempotencyKey): void
    {
        $calls = $this->calls();
        $calls[] = ['operation' => $operation->value, 'idempotency_key' => $idempotencyKey];
        $this->cache->put($this->key('calls'), $calls, self::TTL_SECONDS);
    }

    /**
     * @return array{id: string, account_id: string, status: string, expires_at: string, amount_cents: int, currency: string, capture_method: string, description: string, success_url: string, cancel_url: string, locale: string, metadata: array<string, string>, payment_intent_id: string|null, payment_intent_status: string|null, payment_failed: bool, failure_code: string|null, capture_before: string|null, captured_cents: int, refunded_cents: int}
     */
    private function load(string $sessionId): array
    {
        $session = $this->cache->get($this->key('session:'.$sessionId));

        if (! is_array($session)) {
            throw new LogicException('Sessione di checkout finta inesistente.');
        }

        /** @var array{id: string, account_id: string, status: string, expires_at: string, amount_cents: int, currency: string, capture_method: string, description: string, success_url: string, cancel_url: string, locale: string, metadata: array<string, string>, payment_intent_id: string|null, payment_intent_status: string|null, payment_failed: bool, failure_code: string|null, capture_before: string|null, captured_cents: int, refunded_cents: int} $session */
        return $session;
    }

    /**
     * @return array{id: string, account_id: string, status: string, expires_at: string, amount_cents: int, currency: string, capture_method: string, description: string, success_url: string, cancel_url: string, locale: string, metadata: array<string, string>, payment_intent_id: string|null, payment_intent_status: string|null, payment_failed: bool, failure_code: string|null, capture_before: string|null, captured_cents: int, refunded_cents: int}
     */
    private function loadFor(string $accountId, string $sessionId): array
    {
        $session = $this->load($sessionId);

        if ($session['account_id'] !== $accountId) {
            throw new LogicException('Sessione di checkout di un altro account.');
        }

        return $session;
    }

    /**
     * @return array{id: string, account_id: string, status: string, expires_at: string, amount_cents: int, currency: string, capture_method: string, description: string, success_url: string, cancel_url: string, locale: string, metadata: array<string, string>, payment_intent_id: string|null, payment_intent_status: string|null, payment_failed: bool, failure_code: string|null, capture_before: string|null, captured_cents: int, refunded_cents: int}
     */
    private function loadByPaymentIntent(string $accountId, string $paymentIntentId): array
    {
        $sessionId = $this->cache->get($this->key('pi:'.$paymentIntentId));

        if (! is_string($sessionId)) {
            throw new LogicException('PaymentIntent finto inesistente.');
        }

        return $this->loadFor($accountId, $sessionId);
    }

    /** @param  array<string, mixed>  $session */
    private function save(array $session): void
    {
        $this->cache->put($this->key('session:'.$session['id']), $session, self::TTL_SECONDS);
    }

    /** @param  array{status: string, expires_at: string}  $session */
    private function isPastExpiry(array $session): bool
    {
        return $session['status'] === CheckoutSessionStatus::OPEN->value
            && CarbonImmutable::now()->greaterThanOrEqualTo(CarbonImmutable::parse($session['expires_at']));
    }

    /**
     * @param  array{id: string, status: string, expires_at: string, amount_cents: int, metadata: array<string, string>, payment_intent_id: string|null, payment_intent_status: string|null, payment_failed: bool, failure_code: string|null, capture_before: string|null}  $session
     */
    private function state(array $session): CheckoutSessionState
    {
        // Come Stripe: una sessione aperta oltre expires_at risulta scaduta.
        $status = $this->isPastExpiry($session) ? CheckoutSessionStatus::EXPIRED : CheckoutSessionStatus::from($session['status']);

        return new CheckoutSessionState(
            id: $session['id'],
            status: $status,
            expiresAt: CarbonImmutable::parse($session['expires_at']),
            amountCents: $session['amount_cents'],
            metadata: $session['metadata'],
            paymentIntentId: $session['payment_intent_id'],
            paymentIntentStatus: $session['payment_intent_status'] === null ? null : PaymentIntentStatus::from($session['payment_intent_status']),
            paymentFailed: $session['payment_failed'],
            failureCode: $session['failure_code'],
            captureBefore: $session['capture_before'] === null ? null : CarbonImmutable::parse($session['capture_before']),
        );
    }

    private function digest(string $value): string
    {
        return substr(hash('sha256', $value), 0, 24);
    }

    private function key(string $suffix): string
    {
        return self::PREFIX.$suffix;
    }
}
