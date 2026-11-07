<?php

namespace App\Domain\Payments\Stripe;

use App\Domain\Payments\Gateway\CaptureResult;
use App\Domain\Payments\Gateway\CheckoutSession;
use App\Domain\Payments\Gateway\CheckoutSessionRequest;
use App\Domain\Payments\Gateway\CheckoutSessionState;
use App\Domain\Payments\Gateway\CheckoutSessionStatus;
use App\Domain\Payments\Gateway\DisputeState;
use App\Domain\Payments\Gateway\Exceptions\PaymentDeclined;
use App\Domain\Payments\Gateway\Exceptions\PaymentProviderRejected;
use App\Domain\Payments\Gateway\Exceptions\SessionAlreadyCompleted;
use App\Domain\Payments\Gateway\PaymentGateway;
use App\Domain\Payments\Gateway\PaymentIntentState;
use App\Domain\Payments\Gateway\PaymentIntentStatus;
use App\Domain\Payments\Gateway\RefundResult;
use App\Domain\Payments\Gateway\RefundResultStatus;
use Carbon\CarbonImmutable;
use Stripe\Checkout\Session;
use Stripe\Dispute;
use Stripe\Exception\ApiErrorException;
use Stripe\Exception\CardException;
use Stripe\PaymentIntent;
use Stripe\Refund;
use Stripe\StripeObject;

/**
 * Gateway Stripe Connect con direct charges (05): ogni chiamata porta l'header Stripe-Account
 * dell'account connesso della struttura e, se modifica qualcosa, l'idempotency key ricevuta dal
 * chiamante (`checkout:`, `expire:`, `capture:`, `cancel:`, `refund:` seguiti dall'ID interno).
 *
 * Checkout ospitato da Stripe, solo carta, valuta e importo dal server; capture manuale solo per le
 * richieste (05, flusso B). Un rifiuto della carta dentro una sessione aperta non è un fallimento
 * definitivo: Stripe Checkout lascia riprovare l'ospite fino alla scadenza (ADR-0012). È definitivo
 * solo un PaymentIntent annullato.
 */
final class StripePaymentGateway implements PaymentGateway
{
    public function __construct(private readonly StripeClientFactory $stripe) {}

    public function createCheckoutSession(CheckoutSessionRequest $request): CheckoutSession
    {
        $params = [
            'mode' => 'payment',
            'line_items' => [[
                'quantity' => 1,
                'price_data' => [
                    'currency' => strtolower($request->currency),
                    'unit_amount' => $request->amountCents,
                    'product_data' => ['name' => $request->description],
                ],
            ]],
            'payment_method_types' => ['card'],
            'payment_intent_data' => [
                'capture_method' => strtolower($request->captureMethod->value),
                'metadata' => $request->metadata,
            ],
            'metadata' => $request->metadata,
            'client_reference_id' => $request->metadata['payment_id'],
            'expires_at' => $request->expiresAt->getTimestamp(),
            'success_url' => $request->successUrl,
            'cancel_url' => $request->cancelUrl,
            'locale' => $request->locale->value,
        ];

        if ($request->customerEmail !== null) {
            $params['customer_email'] = $request->customerEmail;
        }

        $session = $this->call('checkout.sessions.create', fn (): Session => $this->stripe->client()->checkout->sessions->create(
            $params, $this->options($request->accountId, $request->idempotencyKey),
        ));

        return new CheckoutSession((string) $session->id, (string) $session->url, CarbonImmutable::createFromTimestampUTC((int) $session->expires_at));
    }

    public function retrieveSession(string $accountId, string $sessionId): CheckoutSessionState
    {
        $session = $this->call('checkout.sessions.retrieve', fn (): Session => $this->stripe->client()->checkout->sessions->retrieve(
            $sessionId, ['expand' => ['payment_intent.latest_charge']], $this->options($accountId),
        ));

        return $this->sessionState($session);
    }

    public function expireSession(string $accountId, string $sessionId, string $idempotencyKey): CheckoutSessionState
    {
        try {
            $session = $this->stripe->client()->checkout->sessions->expire($sessionId, [], $this->options($accountId, $idempotencyKey));

            return $this->sessionState($session);
        } catch (ApiErrorException $e) {
            if (StripeErrors::isTransient($e)) {
                throw StripeErrors::unavailable('checkout.sessions.expire', $e);
            }

            StripeErrors::log('checkout.sessions.expire', $e);
        }

        // Sessione non più chiudibile: si guarda perché (05). Completata → T6; già scaduta → chiusa.
        $state = $this->retrieveSession($accountId, $sessionId);

        return match ($state->status) {
            CheckoutSessionStatus::COMPLETE => throw new SessionAlreadyCompleted($sessionId),
            CheckoutSessionStatus::EXPIRED => $state,
            CheckoutSessionStatus::OPEN => throw new PaymentProviderRejected('checkout.sessions.expire', 'session_still_open'),
        };
    }

    public function capture(string $accountId, string $paymentIntentId, int $amountCents, string $idempotencyKey): CaptureResult
    {
        try {
            $intent = $this->stripe->client()->paymentIntents->capture(
                $paymentIntentId, ['amount_to_capture' => $amountCents], $this->options($accountId, $idempotencyKey),
            );

            return new CaptureResult((string) $intent->id, (int) $intent->amount_received);
        } catch (CardException $e) {
            StripeErrors::log('payment_intents.capture', $e);

            throw new PaymentDeclined($e->getDeclineCode() ?? $e->getStripeCode() ?? 'card_declined');
        } catch (ApiErrorException $e) {
            if (StripeErrors::isTransient($e)) {
                throw StripeErrors::unavailable('payment_intents.capture', $e);
            }

            StripeErrors::log('payment_intents.capture', $e);
            $code = $e->getStripeCode() ?? 'capture_rejected';
        }

        // Già incassato (richiesta ripetuta dopo un esito perso): vale come riuscita.
        $intent = $this->retrievePaymentIntent($accountId, $paymentIntentId);

        if ($intent->status === PaymentIntentStatus::SUCCEEDED) {
            return new CaptureResult($intent->id, $intent->amountReceivedCents);
        }

        throw new PaymentDeclined($code);
    }

    public function cancelAuthorization(string $accountId, string $paymentIntentId, string $idempotencyKey): void
    {
        try {
            $this->stripe->client()->paymentIntents->cancel($paymentIntentId, [], $this->options($accountId, $idempotencyKey));

            return;
        } catch (ApiErrorException $e) {
            if (StripeErrors::isTransient($e)) {
                throw StripeErrors::unavailable('payment_intents.cancel', $e);
            }

            StripeErrors::log('payment_intents.cancel', $e);
        }

        $intent = $this->retrievePaymentIntent($accountId, $paymentIntentId);

        match ($intent->status) {
            PaymentIntentStatus::CANCELED => null,
            PaymentIntentStatus::SUCCEEDED => throw new PaymentDeclined('payment_intent_unexpected_state'),
            default => throw new PaymentProviderRejected('payment_intents.cancel', $intent->status->value),
        };
    }

    public function refund(string $accountId, string $paymentIntentId, int $amountCents, string $idempotencyKey, array $metadata = []): RefundResult
    {
        try {
            $refund = $this->stripe->client()->refunds->create(
                ['payment_intent' => $paymentIntentId, 'amount' => $amountCents, 'metadata' => $metadata],
                $this->options($accountId, $idempotencyKey),
            );
        } catch (ApiErrorException $e) {
            if (StripeErrors::isTransient($e)) {
                throw StripeErrors::unavailable('refunds.create', $e);
            }

            StripeErrors::log('refunds.create', $e);

            throw new PaymentDeclined($e->getStripeCode() ?? 'refund_rejected');
        }

        return $this->refundResult($refund);
    }

    public function retrieveRefund(string $accountId, string $refundId): RefundResult
    {
        return $this->refundResult($this->call('refunds.retrieve', fn (): Refund => $this->stripe->client()->refunds->retrieve(
            $refundId, [], $this->options($accountId),
        )));
    }

    public function retrievePaymentIntent(string $accountId, string $paymentIntentId): PaymentIntentState
    {
        $intent = $this->call('payment_intents.retrieve', fn (): PaymentIntent => $this->stripe->client()->paymentIntents->retrieve(
            $paymentIntentId, ['expand' => ['latest_charge']], $this->options($accountId),
        ));

        return $this->intentState($intent->toArray());
    }

    public function retrieveDispute(string $accountId, string $disputeId): DisputeState
    {
        $dispute = $this->call('disputes.retrieve', fn (): Dispute => $this->stripe->client()->disputes->retrieve(
            $disputeId, [], $this->options($accountId),
        ));
        $intent = $dispute->payment_intent;

        return new DisputeState(
            id: (string) $dispute->id,
            paymentIntentId: is_string($intent) ? $intent : ($intent instanceof PaymentIntent ? (string) $intent->id : null),
            amountCents: (int) $dispute->amount,
            status: (string) $dispute->status,
            reason: $dispute->reason,
        );
    }

    private function sessionState(Session $session): CheckoutSessionState
    {
        $data = $session->toArray();
        $intent = is_array($data['payment_intent'] ?? null) ? $this->intentState($data['payment_intent']) : null;

        return new CheckoutSessionState(
            id: (string) $data['id'],
            status: CheckoutSessionStatus::tryFrom((string) ($data['status'] ?? '')) ?? CheckoutSessionStatus::OPEN,
            expiresAt: CarbonImmutable::createFromTimestampUTC((int) $data['expires_at']),
            amountCents: (int) ($data['amount_total'] ?? 0),
            metadata: self::strings($data['metadata'] ?? []),
            paymentIntentId: $intent !== null ? $intent->id : (is_string($data['payment_intent'] ?? null) ? $data['payment_intent'] : null),
            paymentIntentStatus: $intent?->status,
            paymentFailed: $intent?->status === PaymentIntentStatus::CANCELED,
            failureCode: $intent?->failureCode,
            captureBefore: $intent?->captureBefore,
        );
    }

    /** @param  array<string, mixed>  $data */
    private function intentState(array $data): PaymentIntentState
    {
        $charge = is_array($data['latest_charge'] ?? null) ? $data['latest_charge'] : [];
        $captureBefore = $charge['payment_method_details']['card']['capture_before'] ?? null;
        $error = is_array($data['last_payment_error'] ?? null) ? $data['last_payment_error'] : [];

        return new PaymentIntentState(
            id: (string) $data['id'],
            status: PaymentIntentStatus::tryFrom((string) ($data['status'] ?? '')) ?? PaymentIntentStatus::PROCESSING,
            amountCents: (int) ($data['amount'] ?? 0),
            amountReceivedCents: (int) ($data['amount_received'] ?? 0),
            metadata: self::strings($data['metadata'] ?? []),
            captureBefore: is_int($captureBefore) ? CarbonImmutable::createFromTimestampUTC($captureBefore) : null,
            failureCode: $error['decline_code'] ?? $error['code'] ?? null,
        );
    }

    private function refundResult(Refund $refund): RefundResult
    {
        return new RefundResult((string) $refund->id, match ((string) $refund->status) {
            'succeeded' => RefundResultStatus::SUCCEEDED,
            'failed' => RefundResultStatus::FAILED,
            'canceled' => RefundResultStatus::CANCELED,
            default => RefundResultStatus::PENDING,
        });
    }

    /** @return array{stripe_account: string, idempotency_key?: string} */
    private function options(string $accountId, ?string $idempotencyKey = null): array
    {
        return $idempotencyKey === null
            ? ['stripe_account' => $accountId]
            : ['stripe_account' => $accountId, 'idempotency_key' => $idempotencyKey];
    }

    /**
     * @template T
     *
     * @param  callable(): T  $call
     * @return T
     */
    private function call(string $operation, callable $call): mixed
    {
        try {
            return $call();
        } catch (ApiErrorException $e) {
            if (StripeErrors::isTransient($e)) {
                throw StripeErrors::unavailable($operation, $e);
            }

            StripeErrors::log($operation, $e);

            throw new PaymentProviderRejected($operation, $e->getStripeCode());
        }
    }

    /** @return array<string, string> */
    private static function strings(mixed $values): array
    {
        if ($values instanceof StripeObject) {
            $values = $values->toArray();
        }

        $out = [];

        foreach (is_array($values) ? $values : [] as $key => $value) {
            if (is_string($key) && is_scalar($value)) {
                $out[$key] = (string) $value;
            }
        }

        return $out;
    }
}
