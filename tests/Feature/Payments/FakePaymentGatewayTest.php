<?php

use App\Domain\Payments\Enums\CaptureMethod;
use App\Domain\Payments\Gateway\CheckoutSessionRequest;
use App\Domain\Payments\Gateway\CheckoutSessionStatus;
use App\Domain\Payments\Gateway\Exceptions\FakeGatewayNotAllowed;
use App\Domain\Payments\Gateway\Exceptions\PaymentDeclined;
use App\Domain\Payments\Gateway\Exceptions\PaymentProviderUnavailable;
use App\Domain\Payments\Gateway\Exceptions\SessionAlreadyCompleted;
use App\Domain\Payments\Gateway\Fake\FakeCustomerAction;
use App\Domain\Payments\Gateway\Fake\FakeFailure;
use App\Domain\Payments\Gateway\Fake\FakeOperation;
use App\Domain\Payments\Gateway\Fake\FakePaymentGateway;
use App\Domain\Payments\Gateway\PaymentGateway;
use App\Domain\Payments\Gateway\PaymentIntentStatus;
use App\Domain\Payments\Gateway\RefundResultStatus;
use App\Domain\Tenancy\Enums\Locale;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;

/*
 * 3.3: PaymentGateway e FakePaymentGateway (05, ADR-0006). Gateway deterministico con esiti
 * programmabili; rifiuta di partire fuori da local e testing.
 */

beforeEach(function () {
    Carbon::setTestNow('2027-06-01 10:00:00');
    $this->gateway = app(PaymentGateway::class);
});

afterEach(fn () => Carbon::setTestNow());

function sessionRequest(string $key = 'checkout:p1', CaptureMethod $capture = CaptureMethod::AUTOMATIC): CheckoutSessionRequest
{
    return new CheckoutSessionRequest(
        accountId: 'acct_fake_1',
        idempotencyKey: $key,
        amountCents: 47550,
        currency: 'EUR',
        captureMethod: $capture,
        expiresAt: CarbonImmutable::now()->addMinutes(30)->addMilliseconds(400),
        description: 'Casa Golden',
        successUrl: 'https://example.test/return?session={CHECKOUT_SESSION_ID}',
        cancelUrl: 'https://example.test/cancel',
        locale: Locale::IT,
        customerEmail: null,
        metadata: ['reservation_id' => 'r1', 'payment_id' => 'p1', 'tenant_id' => 't1'],
    );
}

it('è il gateway configurato nei test', function () {
    expect($this->gateway)->toBeInstanceOf(FakePaymentGateway::class);
});

it('crea la sessione con la scadenza restituita dal fornitore e la stessa sessione per la stessa chiave', function () {
    $session = $this->gateway->createCheckoutSession(sessionRequest());
    $again = $this->gateway->createCheckoutSession(sessionRequest());
    $other = $this->gateway->createCheckoutSession(sessionRequest('checkout:p2'));

    expect($session->id)->toStartWith('cs_fake_')
        ->and($again->id)->toBe($session->id)
        ->and($other->id)->not->toBe($session->id)
        ->and($session->url)->toEndWith('/_simulated-checkout/'.$session->id)
        ->and($session->expiresAt->toIso8601String())->toBe('2027-06-01T10:30:00+00:00');

    $state = $this->gateway->retrieveSession('acct_fake_1', $session->id);
    expect($state->status)->toBe(CheckoutSessionStatus::OPEN)
        ->and($state->metadata)->toBe(['reservation_id' => 'r1', 'payment_id' => 'p1', 'tenant_id' => 't1']);
});

it('timeout senza effetto e timeout dopo la creazione: la stessa chiave recupera la sessione', function () {
    $this->gateway->failNext(FakeOperation::CREATE, FakeFailure::TIMEOUT);
    expect(fn () => $this->gateway->createCheckoutSession(sessionRequest()))->toThrow(PaymentProviderUnavailable::class);

    $this->gateway->failNext(FakeOperation::CREATE, FakeFailure::TIMEOUT_AFTER_EFFECT);
    expect(fn () => $this->gateway->createCheckoutSession(sessionRequest()))->toThrow(PaymentProviderUnavailable::class);

    $recovered = $this->gateway->createCheckoutSession(sessionRequest());
    expect($this->gateway->retrieveSession('acct_fake_1', $recovered->id)->status)->toBe(CheckoutSessionStatus::OPEN)
        ->and(array_column($this->gateway->calls(FakeOperation::CREATE), 'idempotency_key'))->toBe(['checkout:p1', 'checkout:p1', 'checkout:p1']);
});

it('pagamento riuscito con capture automatica', function () {
    $session = $this->gateway->createCheckoutSession(sessionRequest());
    $state = $this->gateway->simulateCustomer($session->id, FakeCustomerAction::PAY);

    expect($state->status)->toBe(CheckoutSessionStatus::COMPLETE)
        ->and($state->paymentIntentStatus)->toBe(PaymentIntentStatus::SUCCEEDED)
        ->and($state->isPaid())->toBeTrue()
        ->and($state->paymentIntentId)->toStartWith('pi_fake_');
});

it('autorizzazione con capture manuale, poi capture idempotente', function () {
    $session = $this->gateway->createCheckoutSession(sessionRequest(capture: CaptureMethod::MANUAL));
    $state = $this->gateway->simulateCustomer($session->id, FakeCustomerAction::PAY);

    expect($state->isAuthorized())->toBeTrue()
        ->and($state->captureBefore?->toIso8601String())->toBe('2027-06-08T10:00:00+00:00');

    $result = $this->gateway->capture('acct_fake_1', $state->paymentIntentId, 47550, 'capture:p1');
    $again = $this->gateway->capture('acct_fake_1', $state->paymentIntentId, 47550, 'capture:p1');

    expect($result->capturedCents)->toBe(47550)->and($again->capturedCents)->toBe(47550)
        ->and($this->gateway->retrieveSession('acct_fake_1', $session->id)->isPaid())->toBeTrue();
});

it('capture rifiutata e annullo dell\'autorizzazione', function () {
    $session = $this->gateway->createCheckoutSession(sessionRequest(capture: CaptureMethod::MANUAL));
    $pi = $this->gateway->simulateCustomer($session->id, FakeCustomerAction::PAY)->paymentIntentId;

    $this->gateway->failNext(FakeOperation::CAPTURE, FakeFailure::DECLINED);
    expect(fn () => $this->gateway->capture('acct_fake_1', $pi, 47550, 'capture:p1'))->toThrow(PaymentDeclined::class);

    $this->gateway->cancelAuthorization('acct_fake_1', $pi, 'cancel:p1');
    expect($this->gateway->retrieveSession('acct_fake_1', $session->id)->paymentIntentStatus)->toBe(PaymentIntentStatus::CANCELED);
});

it('pagamento rifiutato: la sessione resta aperta con il tentativo fallito', function () {
    $session = $this->gateway->createCheckoutSession(sessionRequest());
    $state = $this->gateway->simulateCustomer($session->id, FakeCustomerAction::DECLINE);

    expect($state->status)->toBe(CheckoutSessionStatus::OPEN)
        ->and($state->paymentFailed)->toBeTrue()
        ->and($state->failureCode)->toBe('card_declined');
});

it('expire chiude la sessione aperta e rifiuta quella già completata (T6)', function () {
    $open = $this->gateway->createCheckoutSession(sessionRequest('checkout:a'));
    expect($this->gateway->expireSession('acct_fake_1', $open->id, 'expire:a')->status)->toBe(CheckoutSessionStatus::EXPIRED);
    expect($this->gateway->simulateCustomer($open->id, FakeCustomerAction::PAY)->status)->toBe(CheckoutSessionStatus::EXPIRED);

    $paid = $this->gateway->createCheckoutSession(sessionRequest('checkout:b'));
    $this->gateway->simulateCustomer($paid->id, FakeCustomerAction::PAY);
    expect(fn () => $this->gateway->expireSession('acct_fake_1', $paid->id, 'expire:b'))->toThrow(SessionAlreadyCompleted::class);

    $this->gateway->failNext(FakeOperation::EXPIRE, FakeFailure::TIMEOUT);
    $late = $this->gateway->createCheckoutSession(sessionRequest('checkout:c'));
    expect(fn () => $this->gateway->expireSession('acct_fake_1', $late->id, 'expire:c'))->toThrow(PaymentProviderUnavailable::class);
    expect($this->gateway->retrieveSession('acct_fake_1', $late->id)->status)->toBe(CheckoutSessionStatus::OPEN);
});

it('una sessione aperta oltre la scadenza risulta scaduta e non si paga più', function () {
    $session = $this->gateway->createCheckoutSession(sessionRequest());
    Carbon::setTestNow('2027-06-01 10:30:00');

    expect($this->gateway->retrieveSession('acct_fake_1', $session->id)->status)->toBe(CheckoutSessionStatus::EXPIRED)
        ->and($this->gateway->simulateCustomer($session->id, FakeCustomerAction::PAY)->isPaid())->toBeFalse();
});

it('rimborsa fino all\'incassato, con chiave idempotente ed esiti programmabili', function () {
    $session = $this->gateway->createCheckoutSession(sessionRequest());
    $pi = $this->gateway->simulateCustomer($session->id, FakeCustomerAction::PAY)->paymentIntentId;

    $refund = $this->gateway->refund('acct_fake_1', $pi, 20000, 'refund:r1');
    expect($refund->status)->toBe(RefundResultStatus::SUCCEEDED)
        ->and($this->gateway->refund('acct_fake_1', $pi, 20000, 'refund:r1')->refundId)->toBe($refund->refundId);

    expect(fn () => $this->gateway->refund('acct_fake_1', $pi, 27551, 'refund:r2'))->toThrow(PaymentDeclined::class);

    $this->gateway->failNext(FakeOperation::REFUND, FakeFailure::PENDING);
    expect($this->gateway->refund('acct_fake_1', $pi, 27550, 'refund:r3')->status)->toBe(RefundResultStatus::PENDING);

    $this->gateway->failNext(FakeOperation::REFUND, FakeFailure::DECLINED);
    expect(fn () => $this->gateway->refund('acct_fake_1', $pi, 1, 'refund:r4'))->toThrow(PaymentDeclined::class);
});

it('non legge sessioni di un altro account', function () {
    $session = $this->gateway->createCheckoutSession(sessionRequest());

    expect(fn () => $this->gateway->retrieveSession('acct_other', $session->id))->toThrow(LogicException::class);
});

it('rifiuta di partire fuori da local e testing', function (string $environment) {
    expect(fn () => new FakePaymentGateway(cache()->store(), $environment, 'https://example.test'))
        ->toThrow(FakeGatewayNotAllowed::class);

    $original = app()->environment();
    app()->detectEnvironment(fn () => $environment);
    app()->forgetInstance(PaymentGateway::class);

    try {
        expect(fn () => app(PaymentGateway::class))->toThrow(FakeGatewayNotAllowed::class);
    } finally {
        app()->detectEnvironment(fn () => $original);
        app()->forgetInstance(PaymentGateway::class);
    }
})->with(['production', 'staging']);

it('parte in local e testing', function (string $environment) {
    expect(new FakePaymentGateway(cache()->store(), $environment, 'https://example.test'))->toBeInstanceOf(FakePaymentGateway::class);
})->with(['local', 'testing']);
