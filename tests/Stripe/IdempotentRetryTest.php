<?php

/*
 * I9 con Stripe in modalità test: la stessa operazione ripetuta con la stessa idempotency key non
 * duplica capture né rimborsi.
 */

beforeEach(fn () => $this->account = liveStripe());

it('capture e rimborso ripetuti con la stessa chiave: un solo effetto', function () {
    $fx = liveTenant($this->account, 'REQUEST');
    $reservation = startCheckout($fx);
    $payment = livePay($fx, $reservation);
    $intent = (string) $payment->payment_intent_id;
    $key = 'capture:'.$payment->id.':live-test';

    $first = liveGateway()->capture($fx['account'], $intent, $payment->amount_cents, $key);
    $second = liveGateway()->capture($fx['account'], $intent, $payment->amount_cents, $key);
    expect($second)->toEqual($first);

    $refundKey = 'refund:'.$payment->id.':live-test';
    $refundA = liveGateway()->refund($fx['account'], $intent, 1000, $refundKey);
    $refundB = liveGateway()->refund($fx['account'], $intent, 1000, $refundKey);

    $refunds = liveStripeClient()->refunds->all(['payment_intent' => $intent], ['stripe_account' => $fx['account']]);
    expect($refundB->id)->toBe($refundA->id)
        ->and($refunds->data)->toHaveCount(1);
});
