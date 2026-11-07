<?php

namespace App\Domain\Payments\Jobs;

use App\Domain\Payments\Enums\RefundStatus;
use App\Domain\Payments\Gateway\Exceptions\PaymentDeclined;
use App\Domain\Payments\Gateway\Exceptions\PaymentProviderUnavailable;
use App\Domain\Payments\Gateway\PaymentGateway;
use App\Domain\Payments\Models\Payment;
use App\Domain\Payments\Models\Refund;
use App\Domain\Payments\RefundService;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Chiamata di rimborso al fornitore (03 sezione 3) con idempotency key `refund:{refund_id}`, fuori
 * dalle transazioni. Riuscito → SUCCEEDED e solo allora l'email "rimborso completato" (I20);
 * rifiuto → FAILED con il prossimo tentativo programmato (1 h, 6 h, 24 h, RefundService);
 * in attesa → resta PENDING con l'ID di Stripe (lo chiude refund.updated o la riconciliazione).
 */
final class ProcessRefundJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 10;

    /** @var list<int> */
    public array $backoff = [60, 300, 900, 3600];

    public function __construct(public readonly string $tenantId, public readonly string $refundId) {}

    public function handle(TenantContext $tenants, PaymentGateway $gateway, RefundService $service): void
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

        if ($refund->stripe_refund_id !== null) {
            // Già creato su Stripe e in attesa: si rilegge, non si ricrea.
            $service->reconcile($this->tenantId, $refund->id);

            return;
        }

        try {
            $result = $gateway->refund($payment->stripe_account_id, $payment->payment_intent_id, $refund->amount_cents, self::idempotencyKey($refund), [
                'refund_id' => $refund->id, 'payment_id' => $payment->id, 'reservation_id' => $payment->reservation_id, 'tenant_id' => $this->tenantId,
            ]);
        } catch (PaymentDeclined) {
            $service->failPending($this->tenantId, $refund->id);

            return;
        } catch (PaymentProviderUnavailable) {
            $this->release($this->backoff[min($this->attempts() - 1, count($this->backoff) - 1)]);

            return;
        }

        $service->apply($this->tenantId, $refund->id, $result);
    }

    /**
     * `refund:{id}` al primo tentativo; dopo un esito definitivo (rifiuto o rimborso fallito) ogni
     * nuovo tentativo ha una chiave nuova `refund:{id}:{n}`, perché nessun rimborso è in corso e Stripe
     * restituirebbe altrimenti lo stesso esito. Un esito sconosciuto (rete) si ripete con la stessa
     * chiave: attempts non cambia (I9, ADR-0012).
     */
    public static function idempotencyKey(Refund $refund): string
    {
        return $refund->attempts === 0 ? 'refund:'.$refund->id : 'refund:'.$refund->id.':'.$refund->attempts;
    }
}
