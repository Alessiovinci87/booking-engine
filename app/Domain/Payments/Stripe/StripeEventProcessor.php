<?php

namespace App\Domain\Payments\Stripe;

use App\Domain\Payments\CheckoutService;
use App\Domain\Payments\Gateway\Exceptions\PaymentProviderUnavailable;
use App\Domain\Payments\Gateway\PaymentGateway;
use App\Domain\Payments\Models\Payment;
use App\Domain\Payments\Models\Refund;
use App\Domain\Payments\PaymentReconciler;
use App\Domain\Payments\RefundService;
use App\Domain\Payments\StateMachines\PaymentStateMachine;
use App\Domain\Payments\StripeAccountService;
use App\Domain\Tenancy\Outbox\Outbox;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Support\Facades\Log;

/**
 * Elaborazione di un evento Stripe già verificato e attribuito al tenant (05 "Webhook" passo 6).
 * Il contenuto dell'evento serve solo a trovare i nostri record: lo stato si rilegge sempre via API
 * e si applicano solo le transizioni ammesse. Eventi duplicati o fuori ordine non producono effetti
 * doppi (I3): ogni ramo rilegge e confronta con lo stato locale.
 */
final class StripeEventProcessor
{
    public const CHECKOUT = ['checkout.session.completed', 'checkout.session.expired', 'checkout.session.async_payment_succeeded', 'checkout.session.async_payment_failed'];

    public const PAYMENT_INTENT = ['payment_intent.succeeded', 'payment_intent.payment_failed', 'payment_intent.canceled', 'payment_intent.amount_capturable_updated'];

    public const REFUND = ['charge.refunded', 'refund.created', 'refund.updated', 'refund.failed', 'charge.refund.updated'];

    public const DISPUTE = ['charge.dispute.created', 'charge.dispute.updated', 'charge.dispute.closed'];

    public const ACCOUNT = ['account.updated', 'capability.updated'];

    public function __construct(
        private readonly TenantContext $tenants,
        private readonly PaymentGateway $gateway,
        private readonly CheckoutService $checkout,
        private readonly PaymentReconciler $reconciler,
        private readonly RefundService $refunds,
        private readonly PaymentStateMachine $payments,
        private readonly StripeAccountService $accounts,
        private readonly Outbox $outbox,
    ) {}

    public static function handles(string $type): bool
    {
        return in_array($type, [...self::CHECKOUT, ...self::PAYMENT_INTENT, ...self::REFUND, ...self::DISPUTE, ...self::ACCOUNT], true);
    }

    /**
     * @param  array{object_id: string, payment_id: ?string, refund_id: ?string, payment_intent: ?string}  $ref
     * @return 'processed'|'ignored'
     *
     * @throws PaymentProviderUnavailable Stripe non risponde: il job riprova.
     */
    public function process(string $tenantId, string $type, array $ref): string
    {
        $outcome = match (true) {
            in_array($type, self::CHECKOUT, true) => $this->checkout($tenantId, $ref),
            in_array($type, self::PAYMENT_INTENT, true) => $this->paymentIntent($tenantId, $ref),
            in_array($type, self::REFUND, true) => $this->refund($tenantId, $type, $ref),
            in_array($type, self::DISPUTE, true) => $this->dispute($tenantId, $ref),
            in_array($type, self::ACCOUNT, true) => $this->accounts->sync($tenantId) === null ? 'ignored' : 'processed',
            default => 'ignored',
        };

        return $outcome === 'ignored' ? 'ignored' : 'processed';
    }

    /** @param  array{object_id: string, payment_id: ?string, refund_id: ?string, payment_intent: ?string}  $ref */
    private function checkout(string $tenantId, array $ref): string
    {
        $paymentId = $this->tenants->run($tenantId, fn (): ?string => Payment::query()->where('checkout_session_id', $ref['object_id'])->value('id'));

        if (! is_string($paymentId) && $ref['payment_id'] !== null) {
            // Sessione creata da Stripe ma non salvata (crash tra passo 2 e 3 di 05): la si adotta
            // dopo averla riletta, se appartiene davvero a quel pagamento.
            $paymentId = $this->checkout->adoptSession($tenantId, $ref['payment_id'], $ref['object_id']) ? $ref['payment_id'] : null;
        }

        if (! is_string($paymentId)) {
            return 'ignored';
        }

        return $this->retryable($this->reconciler->payment($tenantId, $paymentId));
    }

    /** @param  array{object_id: string, payment_id: ?string, refund_id: ?string, payment_intent: ?string}  $ref */
    private function paymentIntent(string $tenantId, array $ref): string
    {
        $paymentId = $this->tenants->run($tenantId, fn (): ?string => Payment::query()->where('payment_intent_id', $ref['object_id'])->value('id')
            ?? ($ref['payment_id'] === null ? null : Payment::query()->whereKey($ref['payment_id'])->value('id')));

        return is_string($paymentId) ? $this->retryable($this->reconciler->payment($tenantId, $paymentId)) : 'ignored';
    }

    /** @param  array{object_id: string, payment_id: ?string, refund_id: ?string, payment_intent: ?string}  $ref */
    private function refund(string $tenantId, string $type, array $ref): string
    {
        // charge.refunded porta la Charge: si rileggono tutti i rimborsi in attesa del pagamento.
        $targets = $this->tenants->run($tenantId, function () use ($type, $ref): array {
            if ($type === 'charge.refunded') {
                $paymentId = Payment::query()->where('payment_intent_id', $ref['payment_intent'])->value('id');

                return Refund::query()->where('payment_id', $paymentId)->where('status', 'PENDING')->pluck('id')
                    ->map(fn (string $id): array => [$id, null])->all();
            }

            $id = Refund::query()->where('stripe_refund_id', $ref['object_id'])->value('id')
                ?? ($ref['refund_id'] === null ? null : Refund::query()->whereKey($ref['refund_id'])->value('id'));

            return is_string($id) ? [[$id, $ref['object_id']]] : [];
        });

        if ($targets === []) {
            return 'ignored';
        }

        foreach ($targets as [$refundId, $providerId]) {
            // Un evento sul singolo rimborso può annullare un rimborso già riuscito (ADR-0012 punto 23).
            $this->retryable($this->refunds->reconcile($tenantId, $refundId, $providerId, allowReversal: $type !== 'charge.refunded'));
        }

        return 'processed';
    }

    /** @param  array{object_id: string, payment_id: ?string, refund_id: ?string, payment_intent: ?string}  $ref */
    private function dispute(string $tenantId, array $ref): string
    {
        $account = $this->tenants->run($tenantId, fn (): ?string => Payment::query()->where('payment_intent_id', $ref['payment_intent'])->value('stripe_account_id'));

        if (! is_string($account)) {
            return 'ignored';
        }

        $dispute = $this->gateway->retrieveDispute($account, $ref['object_id']);

        return $this->tenants->run($tenantId, function () use ($dispute): string {
            $payment = Payment::query()->where('payment_intent_id', $dispute->paymentIntentId)->first();

            if ($payment === null) {
                return 'ignored';
            }

            $type = $dispute->isClosed() ? 'dispute_closed' : 'dispute_created';

            if ($this->payments->recordDispute($payment, $type, $dispute->amountCents, $dispute->id) && $type === 'dispute_created') {
                Log::error('payments.dispute_opened', ['payment_id' => $payment->id, 'reservation_id' => $payment->reservation_id, 'dispute_id' => $dispute->id]);
                $this->outbox->enqueue('reservation.dispute_opened', 'reservation.dispute_opened:'.$dispute->id, [
                    'reservation_id' => $payment->reservation_id, 'payment_id' => $payment->id,
                ]);
            }

            return 'processed';
        });
    }

    private function retryable(string $outcome): string
    {
        if ($outcome === 'retry') {
            throw PaymentProviderUnavailable::make('webhook');
        }

        return 'processed';
    }
}
