<?php

use App\Domain\Payments\CheckoutOutcomes;
use App\Domain\Payments\Gateway\CaptureResult;
use App\Domain\Payments\Gateway\CheckoutSession;
use App\Domain\Payments\Gateway\CheckoutSessionRequest;
use App\Domain\Payments\Gateway\CheckoutSessionState;
use App\Domain\Payments\Gateway\CheckoutSessionStatus;
use App\Domain\Payments\Gateway\ConnectedAccounts;
use App\Domain\Payments\Gateway\DisputeState;
use App\Domain\Payments\Gateway\Exceptions\SessionAlreadyCompleted;
use App\Domain\Payments\Gateway\PaymentGateway;
use App\Domain\Payments\Gateway\PaymentIntentState;
use App\Domain\Payments\Gateway\RefundResult;
use App\Domain\Payments\Models\Payment;
use App\Domain\Payments\Stripe\StripeClientFactory;
use App\Domain\Payments\Stripe\StripePaymentGateway;
use App\Domain\Reservations\Models\Reservation;
use Illuminate\Support\Facades\DB;
use Stripe\ApiRequestor;
use Stripe\StripeClient;

/*
 * Suite Stripe/* (10-TRACEABILITY): API di Stripe in modalità test, mai in CI ordinaria.
 *
 * - Si attiva solo con STRIPE_LIVE_TESTS=1, così nessun giro locale chiama Stripe per sbaglio.
 * - STRIPE_SECRET deve essere una chiave sk_test_ (variabile d'ambiente o segreto della CI notturna);
 *   senza, i test vengono saltati. La chiave non viene mai stampata.
 * - L'account connesso è STRIPE_TEST_CONNECTED_ACCOUNT, oppure il primo account connesso con
 *   charges_enabled trovato con l'API; se non ce n'è nessuno i test vengono saltati.
 * - Una Checkout Session non si può completare lato server: l'ospite paga con un PaymentIntent reale
 *   confermato con una carta di test (pm_card_visa…) sull'account connesso, con gli stessi metadata, e
 *   LiveCheckoutGateway presenta la sessione come completata con quel PaymentIntent. Tutte le altre
 *   chiamate (sessioni, expire, capture, annullo, rimborsi, riletture) sono reali.
 */

final class LiveCheckoutGateway implements PaymentGateway
{
    /** @var array<string, string> sessione → PaymentIntent pagato */
    public array $completed = [];

    public function __construct(private readonly StripePaymentGateway $inner) {}

    public function createCheckoutSession(CheckoutSessionRequest $request): CheckoutSession
    {
        return $this->inner->createCheckoutSession($request);
    }

    public function retrieveSession(string $accountId, string $sessionId): CheckoutSessionState
    {
        $state = $this->inner->retrieveSession($accountId, $sessionId);

        if (! isset($this->completed[$sessionId])) {
            return $state;
        }

        $intent = $this->inner->retrievePaymentIntent($accountId, $this->completed[$sessionId]);

        return new CheckoutSessionState(
            $state->id, CheckoutSessionStatus::COMPLETE, $state->expiresAt, $state->amountCents, $state->metadata,
            $intent->id, $intent->status, false, $intent->failureCode, $intent->captureBefore,
        );
    }

    public function expireSession(string $accountId, string $sessionId, string $idempotencyKey): CheckoutSessionState
    {
        if (isset($this->completed[$sessionId])) {
            throw new SessionAlreadyCompleted;
        }

        return $this->inner->expireSession($accountId, $sessionId, $idempotencyKey);
    }

    public function capture(string $accountId, string $paymentIntentId, int $amountCents, string $idempotencyKey): CaptureResult
    {
        return $this->inner->capture($accountId, $paymentIntentId, $amountCents, $idempotencyKey);
    }

    public function cancelAuthorization(string $accountId, string $paymentIntentId, string $idempotencyKey): void
    {
        $this->inner->cancelAuthorization($accountId, $paymentIntentId, $idempotencyKey);
    }

    public function refund(string $accountId, string $paymentIntentId, int $amountCents, string $idempotencyKey, array $metadata = []): RefundResult
    {
        return $this->inner->refund($accountId, $paymentIntentId, $amountCents, $idempotencyKey, $metadata);
    }

    public function retrieveRefund(string $accountId, string $refundId): RefundResult
    {
        return $this->inner->retrieveRefund($accountId, $refundId);
    }

    public function retrievePaymentIntent(string $accountId, string $paymentIntentId): PaymentIntentState
    {
        return $this->inner->retrievePaymentIntent($accountId, $paymentIntentId);
    }

    public function retrieveDispute(string $accountId, string $disputeId): DisputeState
    {
        return $this->inner->retrieveDispute($accountId, $disputeId);
    }
}

/** Configura il gateway reale con la chiave di test e restituisce l'account connesso da usare. */
function liveStripe(): string
{
    if (getenv('STRIPE_LIVE_TESTS') !== '1') {
        test()->markTestSkipped('Suite Stripe/* disattivata: impostare STRIPE_LIVE_TESTS=1.');
    }

    $secret = (string) (getenv('STRIPE_SECRET') ?: ($_ENV['STRIPE_SECRET'] ?? ''));

    if (! str_starts_with($secret, 'sk_test_')) {
        test()->markTestSkipped('STRIPE_SECRET di test non impostata.');
    }

    ApiRequestor::setHttpClient(null);
    config(['payments.gateway' => 'stripe', 'services.stripe.secret' => $secret, 'services.stripe.webhook_secret' => 'whsec_test_live_suite']);
    app()->forgetInstance(PaymentGateway::class);
    app()->forgetInstance(ConnectedAccounts::class);
    app()->forgetInstance(StripeClientFactory::class);

    $account = (string) (getenv('STRIPE_TEST_CONNECTED_ACCOUNT') ?: '');

    if ($account === '') {
        foreach (liveStripeClient()->accounts->all(['limit' => 50])->data as $candidate) {
            if ($candidate->charges_enabled === true) {
                $account = (string) $candidate->id;
                break;
            }
        }
    }

    if ($account === '') {
        test()->markTestSkipped('Nessun account connesso con charges_enabled: completare prima `php artisan stripe:demo-account`.');
    }

    $gateway = new LiveCheckoutGateway(app(StripePaymentGateway::class));
    app()->instance(PaymentGateway::class, $gateway);

    return $account;
}

function liveStripeClient(): StripeClient
{
    return app(StripeClientFactory::class)->client();
}

function liveGateway(): LiveCheckoutGateway
{
    $gateway = app(PaymentGateway::class);

    return $gateway instanceof LiveCheckoutGateway ? $gateway : throw new LogicException('Suite Stripe non configurata.');
}

/** Tenant prenotabile collegato all'account connesso reale. */
function liveTenant(string $account, string $bookingMode = 'INSTANT'): array
{
    $fx = bookableTenant($bookingMode);
    inTenant($fx['tenant'], fn () => DB::table('stripe_accounts')->update(['stripe_account_id' => $account]));

    return [...$fx, 'account' => $account];
}

/**
 * L'ospite paga la sessione del Payment più recente con una carta di test (PaymentIntent reale con gli
 * stessi metadata), poi l'esito arriva come dal webhook checkout.session.completed (rilettura via API).
 */
function livePay(array $fx, Reservation $reservation, string $paymentMethod = 'pm_card_visa', bool $deliver = true): Payment
{
    $payment = latestPayment($fx, $reservation->id);
    $session = liveGateway()->retrieveSession($fx['account'], (string) $payment->checkout_session_id);

    $intent = liveStripeClient()->paymentIntents->create([
        'amount' => $payment->amount_cents,
        'currency' => 'eur',
        'payment_method' => $paymentMethod,
        'payment_method_types' => ['card'],
        'confirm' => true,
        'capture_method' => strtolower($payment->capture_method->value),
        'metadata' => $session->metadata,
    ], ['stripe_account' => $fx['account'], 'idempotency_key' => 'live-pay:'.$payment->id]);

    liveGateway()->completed[(string) $payment->checkout_session_id] = (string) $intent->id;

    if ($deliver) {
        app(CheckoutOutcomes::class)->process($fx['tenant'], $payment->id);
    }

    return latestPayment($fx, $reservation->id);
}
