<?php

namespace App\Domain\Payments\Jobs;

use App\Domain\Payments\Enums\RefundStatus;
use App\Domain\Payments\Gateway\Exceptions\PaymentDeclined;
use App\Domain\Payments\Gateway\Exceptions\PaymentProviderUnavailable;
use App\Domain\Payments\Gateway\PaymentGateway;
use App\Domain\Payments\Gateway\RefundResultStatus;
use App\Domain\Payments\Models\Payment;
use App\Domain\Payments\Models\Refund;
use App\Domain\Payments\StateMachines\RefundStateMachine;
use App\Domain\Tenancy\Outbox\Outbox;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Chiamata di rimborso al fornitore (03 sezione 3) con idempotency key `refund:{refund_id}`, fuori
 * dalle transazioni. Riuscito → SUCCEEDED e solo allora l'email "rimborso completato" (I20);
 * rifiuto → FAILED con il prossimo tentativo programmato (1 h, 6 h, 24 h; retry e allerta in M4);
 * in attesa → resta PENDING (lo chiude refund.updated o la riconciliazione, M4).
 */
final class ProcessRefundJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 10;

    /** @var list<int> */
    public array $backoff = [60, 300, 900, 3600];

    public function __construct(public readonly string $tenantId, public readonly string $refundId) {}

    public function handle(TenantContext $tenants, PaymentGateway $gateway, RefundStateMachine $refunds, Outbox $outbox): void
    {
        $loaded = $tenants->run($this->tenantId, function (): ?array {
            $refund = Refund::query()->whereKey($this->refundId)->where('status', RefundStatus::PENDING->value)->first();

            return $refund === null ? null : [$refund, Payment::query()->findOrFail($refund->payment_id)];
        });

        if ($loaded === null) {
            return;
        }

        [$refund, $payment] = $loaded;

        if ($payment->payment_intent_id === null || $payment->stripe_account_id === null) {
            return;
        }

        try {
            $result = $gateway->refund($payment->stripe_account_id, $payment->payment_intent_id, $refund->amount_cents, 'refund:'.$refund->id);
        } catch (PaymentDeclined) {
            $tenants->run($this->tenantId, fn () => $this->whenPending($refund, fn (Refund $r) => $refunds->markFailed($r)));

            return;
        } catch (PaymentProviderUnavailable) {
            $this->release($this->backoff[min($this->attempts() - 1, count($this->backoff) - 1)]);

            return;
        }

        if ($result->status === RefundResultStatus::PENDING) {
            return;
        }

        $tenants->run($this->tenantId, fn () => $this->whenPending($refund, function (Refund $r) use ($refunds, $result, $outbox, $payment): void {
            if ($result->status === RefundResultStatus::FAILED) {
                $refunds->markFailed($r);

                return;
            }

            $refunds->markSucceeded($r, $result->refundId);
            $outbox->enqueue('reservation.refund_completed', 'reservation.refund_completed:'.$r->id, [
                'reservation_id' => $payment->reservation_id, 'refund_id' => $r->id,
            ]);
        }));
    }

    /** @param  callable(Refund): mixed  $apply */
    private function whenPending(Refund $refund, callable $apply): void
    {
        $locked = Refund::query()->lockForUpdate()->findOrFail($refund->id);

        if ($locked->status === RefundStatus::PENDING) {
            $apply($locked);
        }
    }
}
