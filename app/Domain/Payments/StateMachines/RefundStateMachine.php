<?php

namespace App\Domain\Payments\StateMachines;

use App\Domain\Payments\Enums\RefundMethod;
use App\Domain\Payments\Enums\RefundStatus as S;
use App\Domain\Payments\Models\Payment;
use App\Domain\Payments\Models\Refund;
use App\Domain\Reservations\Exceptions\IllegalTransition;
use App\Domain\Tenancy\Audit\AuditLogger;
use Carbon\CarbonImmutable;

/**
 * Macchina a stati del rimborso (03, sezione 3). La chiamata al fornitore avviene fuori dalla
 * transazione, con idempotency key `refund:{id}` (RefundProcessor). reason è un campo personale (09):
 * nell'audit resta solo "[personal]".
 */
final class RefundStateMachine
{
    /** Attese dei tentativi automatici dopo un errore: 1 h, 6 h, 24 h (05). */
    public const RETRY_HOURS = [1, 6, 24];

    /** @var array<string, list<S>> stato di arrivo → stati di partenza ammessi */
    private const TRANSITIONS = [
        'SUCCEEDED' => [S::PENDING, S::FAILED],
        'FAILED' => [S::PENDING],
        'PENDING' => [S::FAILED],
        'CANCELLED' => [S::PENDING],
    ];

    public function __construct(
        private readonly AuditLogger $audit,
        private readonly PaymentStateMachine $payments,
    ) {}

    public function createPending(Payment $payment, int $amountCents, ?string $reason, ?string $requestedBy): Refund
    {
        $refund = $this->newRefund($payment, $amountCents, RefundMethod::STRIPE, $reason, $requestedBy);
        $refund->status = S::PENDING;
        $refund->save();

        $this->audit->record('refund.created', 'refund', $refund->id, [
            'payment_id' => $payment->id, 'amount_cents' => $amountCents, 'method' => RefundMethod::STRIPE->value,
            'reason' => $reason === null ? null : '[personal]',
        ]);

        return $refund;
    }

    /** Rimborso di un pagamento OFFLINE, registrato dal gestore: nasce SUCCEEDED (05). */
    public function createOffline(Payment $payment, int $amountCents, ?string $reason, ?string $requestedBy): Refund
    {
        $refund = $this->newRefund($payment, $amountCents, RefundMethod::OFFLINE, $reason, $requestedBy);
        $refund->status = S::SUCCEEDED;
        $refund->completed_at = CarbonImmutable::now();
        $refund->save();

        $this->audit->record('refund.offline_recorded', 'refund', $refund->id, [
            'payment_id' => $payment->id, 'amount_cents' => $amountCents, 'reason' => $reason === null ? null : '[personal]',
        ]);
        $this->payments->applyRefund($payment, $this->refundedCents($payment));

        return $refund;
    }

    public function markSucceeded(Refund $refund, ?string $providerRefundId): void
    {
        $refund->stripe_refund_id ??= $providerRefundId;
        $refund->completed_at = CarbonImmutable::now();
        $refund->next_attempt_at = null;
        $this->move($refund, S::SUCCEEDED);

        $payment = Payment::query()->findOrFail($refund->payment_id);
        $this->payments->applyRefund($payment, $this->refundedCents($payment));
    }

    /** Errore definitivo: nuovo tentativo automatico dopo 1 h, 6 h, 24 h, poi allerta (05). */
    public function markFailed(Refund $refund): void
    {
        $refund->attempts++;
        $wait = self::RETRY_HOURS[$refund->attempts - 1] ?? null;
        $refund->next_attempt_at = $wait === null ? null : CarbonImmutable::now()->addHours($wait);
        $this->move($refund, S::FAILED);
    }

    private function newRefund(Payment $payment, int $amountCents, RefundMethod $method, ?string $reason, ?string $requestedBy): Refund
    {
        $refund = new Refund;
        $refund->tenant_id = $payment->tenant_id;
        $refund->payment_id = $payment->id;
        $refund->amount_cents = $amountCents;
        $refund->method = $method;
        $refund->reason = $reason;
        $refund->requested_by = $requestedBy;

        return $refund;
    }

    private function refundedCents(Payment $payment): int
    {
        return (int) Refund::query()
            ->where('payment_id', $payment->id)
            ->where('status', S::SUCCEEDED->value)
            ->sum('amount_cents');
    }

    private function move(Refund $refund, S $to): void
    {
        $from = $refund->status;

        if (! in_array($from, self::TRANSITIONS[$to->value], true)) {
            throw IllegalTransition::of('refund', $from->value, $to->value);
        }

        $refund->status = $to;
        $refund->save();

        $this->audit->record('refund.'.strtolower($to->value), 'refund', $refund->id, [
            'status' => ['from' => $from->value, 'to' => $to->value], 'payment_id' => $refund->payment_id,
        ]);
    }
}
