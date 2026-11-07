<?php

namespace App\Domain\Payments;

use App\Domain\Communications\Alerts\AlertSignal;
use App\Domain\Communications\Alerts\OperationalAlerts;
use App\Domain\Payments\Enums\RefundMethod;
use App\Domain\Payments\Enums\RefundStatus;
use App\Domain\Payments\Gateway\Exceptions\PaymentProviderUnavailable;
use App\Domain\Payments\Gateway\PaymentGateway;
use App\Domain\Payments\Gateway\RefundResult;
use App\Domain\Payments\Gateway\RefundResultStatus;
use App\Domain\Payments\Jobs\ProcessRefundJob;
use App\Domain\Payments\Models\Payment;
use App\Domain\Payments\Models\Refund;
use App\Domain\Payments\StateMachines\RefundStateMachine;
use App\Domain\Tenancy\Outbox\Outbox;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Rimborsi (03 sezione 3, 05 "Cancellazioni e rimborsi"). Le chiamate a Stripe sono fuori dalle
 * transazioni; l'esito si applica solo se il rimborso è ancora nello stato atteso.
 *
 * L'email "rimborso completato" parte solo con il Refund SUCCEEDED (I20).
 */
final class RefundService
{
    public function __construct(
        private readonly TenantContext $tenants,
        private readonly PaymentGateway $gateway,
        private readonly RefundStateMachine $refunds,
        private readonly Outbox $outbox,
    ) {}

    /**
     * Rilegge da Stripe un rimborso PENDING (refund.updated, charge.refunded, riconciliazione). Se il
     * rimborso locale non ha ancora l'ID di Stripe, si usa quello dell'evento (rimborso creato con i
     * nostri metadata ma risposta persa).
     *
     * @return 'applied'|'unchanged'|'retry'
     */
    public function reconcile(string $tenantId, string $refundId, ?string $providerRefundId = null, bool $allowReversal = false): string
    {
        // Un rimborso SUCCEEDED si rilegge solo per un evento di Stripe su quel rimborso (ADR-0012 punto 23).
        $statuses = $allowReversal ? [RefundStatus::PENDING->value, RefundStatus::SUCCEEDED->value] : [RefundStatus::PENDING->value];

        $loaded = $this->tenants->run($tenantId, function () use ($refundId, $statuses): ?array {
            $refund = Refund::query()->whereKey($refundId)->whereIn('status', $statuses)
                ->where('method', RefundMethod::STRIPE->value)->first();

            return $refund === null ? null : [$refund, Payment::query()->findOrFail($refund->payment_id)];
        });

        if ($loaded === null) {
            return 'unchanged';
        }

        /** @var Payment $payment */
        [$refund, $payment] = $loaded;
        $providerId = $refund->stripe_refund_id ?? $providerRefundId;

        if ($providerId === null || $payment->stripe_account_id === null) {
            return 'unchanged';
        }

        try {
            $result = $this->gateway->retrieveRefund($payment->stripe_account_id, $providerId);
        } catch (PaymentProviderUnavailable) {
            return 'retry';
        }

        if ($refund->status === RefundStatus::SUCCEEDED) {
            return $this->reverse($tenantId, $refund->id, $result) ? 'applied' : 'unchanged';
        }

        return $this->apply($tenantId, $refund->id, $result) ? 'applied' : 'unchanged';
    }

    /**
     * Rimborso già riuscito che Stripe, riletto via API, dà per fallito: SUCCEEDED → FAILED, allerta
     * urgente ed email al gestore; nessuna email automatica all'ospite (ADR-0012 punto 23).
     */
    private function reverse(string $tenantId, string $refundId, RefundResult $result): bool
    {
        if ($result->status !== RefundResultStatus::FAILED) {
            return false;
        }

        return $this->tenants->run($tenantId, function () use ($refundId, $result): bool {
            $refund = Refund::query()->lockForUpdate()->findOrFail((string) $refundId);

            if ($refund->status !== RefundStatus::SUCCEEDED || $refund->stripe_refund_id !== $result->refundId) {
                return false;
            }

            $this->refunds->markReversedByProvider($refund);
            $payment = Payment::query()->findOrFail($refund->payment_id);
            app(OperationalAlerts::class)->raise(AlertSignal::REFUND_REVERSED, ['refund_id' => $refund->id, 'payment_id' => $payment->id], key: $refund->id);
            $this->outbox->enqueue('reservation.refund_reversed', 'reservation.refund_reversed:'.$refund->id.':'.$result->refundId, [
                'reservation_id' => $payment->reservation_id, 'refund_id' => $refund->id,
            ]);

            return true;
        });
    }

    /**
     * Rifiuto definitivo della chiamata di rimborso (PaymentDeclined): FAILED con il prossimo
     * tentativo automatico, o allerta dopo l'ultimo.
     */
    public function failPending(string $tenantId, string $refundId): void
    {
        $this->tenants->run($tenantId, function () use ($refundId): void {
            $refund = Refund::query()->lockForUpdate()->findOrFail($refundId);

            if ($refund->status === RefundStatus::PENDING) {
                $this->fail($refund);
            }
        });
    }

    /**
     * "Riprova" del gestore (07 POST /admin/refunds/{id}/retry): FAILED → PENDING e nuova chiamata
     * dopo il commit. Va chiamato dentro il contesto del tenant.
     */
    public function retry(Refund $refund): Refund
    {
        $locked = Refund::query()->lockForUpdate()->findOrFail($refund->id);
        $this->refunds->requeue($locked, byManager: true);
        $tenantId = $locked->tenant_id;
        DB::afterCommit(fn () => ProcessRefundJob::dispatch($tenantId, $locked->id));

        return $locked;
    }

    /**
     * "Segna come rimborsato fuori piattaforma" (07 POST /admin/refunds/{id}/mark-offline). Il
     * rimborso diventa SUCCEEDED con metodo OFFLINE e parte l'email "rimborso completato" (I20: ora
     * il rimborso è avvenuto). Va chiamato dentro il contesto del tenant.
     */
    public function markOffline(Refund $refund, string $note): Refund
    {
        $locked = Refund::query()->lockForUpdate()->findOrFail($refund->id);
        $this->refunds->markOffline($locked, $note);
        $this->enqueueCompleted($locked);

        return $locked;
    }

    /**
     * Tentativi automatici (05: 1 h, 6 h, 24 h): i rimborsi FAILED con next_attempt_at scaduto tornano
     * PENDING e si richiamano. Restituisce il numero di rimborsi rimessi in coda.
     */
    public function retryDue(string $tenantId): int
    {
        $due = $this->tenants->run($tenantId, fn (): array => Refund::query()
            ->where('status', RefundStatus::FAILED->value)
            ->whereNotNull('next_attempt_at')
            ->where('next_attempt_at', '<=', now())
            ->limit(100)->pluck('id')->all());

        foreach ($due as $refundId) {
            $this->tenants->run($tenantId, function () use ($refundId, $tenantId): void {
                $refund = Refund::query()->lockForUpdate()->findOrFail((string) $refundId);

                if ($refund->status === RefundStatus::FAILED && $refund->next_attempt_at !== null) {
                    $this->refunds->requeue($refund, byManager: false);
                    DB::afterCommit(fn () => ProcessRefundJob::dispatch($tenantId, $refundId));
                }
            });
        }

        return count($due);
    }

    private function fail(Refund $refund): void
    {
        $this->refunds->markFailed($refund);

        if ($refund->next_attempt_at === null) {
            // Esauriti i tentativi automatici: allerta al gestore (05, 08).
            $payment = Payment::query()->findOrFail($refund->payment_id);
            app(OperationalAlerts::class)->raise(AlertSignal::REFUND_FAILED_FINAL, ['refund_id' => $refund->id, 'attempts' => $refund->attempts]);
            $this->outbox->enqueue('reservation.refund_failed', 'reservation.refund_failed:'.$refund->id.':'.$refund->attempts, [
                'reservation_id' => $payment->reservation_id, 'refund_id' => $refund->id,
            ]);
        }
    }

    private function enqueueCompleted(Refund $refund): void
    {
        $payment = Payment::query()->findOrFail($refund->payment_id);
        $this->outbox->enqueue('reservation.refund_completed', 'reservation.refund_completed:'.$refund->id, [
            'reservation_id' => $payment->reservation_id, 'refund_id' => $refund->id,
        ]);
    }

    /** Esito di Stripe sul rimborso PENDING. Restituisce true se lo stato è cambiato. */
    public function apply(string $tenantId, string $refundId, RefundResult $result): bool
    {
        return $this->tenants->run($tenantId, function () use ($refundId, $result): bool {
            $refund = Refund::query()->lockForUpdate()->findOrFail($refundId);

            if ($refund->status !== RefundStatus::PENDING) {
                return false;
            }

            $this->refunds->attachProviderId($refund, $result->refundId);

            switch ($result->status) {
                case RefundResultStatus::PENDING:
                    return false;
                case RefundResultStatus::SUCCEEDED:
                    $this->refunds->markSucceeded($refund, $result->refundId);
                    $this->enqueueCompleted($refund);

                    return true;
                case RefundResultStatus::FAILED:
                    $this->fail($refund);

                    return true;
                case RefundResultStatus::CANCELED:
                    $this->refunds->markCancelled($refund);
                    Log::warning('payments.refund_cancelled', ['refund_id' => $refund->id]);

                    return true;
            }
        });
    }
}
