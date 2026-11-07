<?php

namespace App\Domain\Payments;

use App\Domain\Payments\Models\Payment;
use App\Domain\Payments\Models\StripeAccount;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Gestione degli eventi del fornitore di pagamento (05, "Webhook", passi 3, 4 e 6). Riceve eventi già
 * verificati dal webhook di Stripe (M4) o dal checkout simulato (3.3) e li tratta allo stesso modo:
 *
 * - tenant dai metadata, ma solo se l'account connesso dell'evento è quello del tenant: altrimenti
 *   l'evento si scarta con un'allerta di sicurezza (passo 4);
 * - pagamento cercato per checkout_session_id dentro il tenant;
 * - il contenuto dell'evento non conta: CheckoutOutcomes rilegge la sessione dal fornitore (passo 6).
 */
final class PaymentEventHandler
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly CheckoutOutcomes $outcomes,
    ) {}

    public function handle(PaymentProviderEvent $event): void
    {
        $tenantId = $event->metadata['tenant_id'] ?? null;

        if (! is_string($tenantId) || ! Str::isUuid($tenantId)) {
            $this->reject($event, 'metadata_tenant_missing');

            return;
        }

        $paymentId = $this->tenantContext->run($tenantId, function () use ($event): ?string {
            if (StripeAccount::query()->value('stripe_account_id') !== $event->accountId) {
                $this->reject($event, 'tenant_account_mismatch');

                return null;
            }

            $id = Payment::query()->where('checkout_session_id', $event->checkoutSessionId)->value('id');

            if (! is_string($id)) {
                // Sessione non ancora salvata (crash tra passo 2 e 3 di 05): la recupera il job di
                // rilascio tramite idempotency key.
                Log::info('payments.event_without_payment', ['event_id' => $event->id, 'type' => $event->type]);

                return null;
            }

            return $id;
        });

        if ($paymentId !== null) {
            $this->outcomes->process($tenantId, $paymentId);
        }
    }

    private function reject(PaymentProviderEvent $event, string $reason): void
    {
        Log::warning('payments.event_rejected', ['event_id' => $event->id, 'type' => $event->type, 'reason' => $reason]);
    }
}
