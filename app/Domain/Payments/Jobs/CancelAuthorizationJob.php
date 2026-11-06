<?php

namespace App\Domain\Payments\Jobs;

use App\Domain\Payments\Enums\PaymentStatus;
use App\Domain\Payments\Gateway\Exceptions\PaymentProviderUnavailable;
use App\Domain\Payments\Gateway\PaymentGateway;
use App\Domain\Payments\Models\Payment;
use App\Domain\Payments\StateMachines\PaymentStateMachine;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Annullo dell'autorizzazione dopo rifiuto, scadenza (T10, T11) o pagamento tardivo (T7), con
 * idempotency key `cancel:{payment_id}` e retry con backoff (05 flusso B, passo 5). L'autorizzazione
 * non annullata non può comunque produrre incassi: la capture parte solo da CAPTURING.
 */
final class CancelAuthorizationJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 10;

    /** @var list<int> */
    public array $backoff = [60, 300, 900, 3600, 7200, 14400];

    public function __construct(public readonly string $tenantId, public readonly string $paymentId) {}

    public function handle(TenantContext $tenants, PaymentGateway $gateway, PaymentStateMachine $payments): void
    {
        $payment = $tenants->run($this->tenantId, fn (): ?Payment => Payment::query()
            ->whereKey($this->paymentId)->where('status', PaymentStatus::AUTHORIZED->value)->first());

        if ($payment === null || $payment->payment_intent_id === null || $payment->stripe_account_id === null) {
            return;
        }

        try {
            $gateway->cancelAuthorization($payment->stripe_account_id, $payment->payment_intent_id, 'cancel:'.$payment->id);
        } catch (PaymentProviderUnavailable $e) {
            $this->release($this->backoff[min($this->attempts() - 1, count($this->backoff) - 1)]);

            return;
        }

        $tenants->run($this->tenantId, function () use ($payment, $payments): void {
            $locked = Payment::query()->lockForUpdate()->findOrFail($payment->id);

            if ($locked->status === PaymentStatus::AUTHORIZED) {
                $payments->markAuthorizationCancelled($locked);
            }
        });
    }
}
