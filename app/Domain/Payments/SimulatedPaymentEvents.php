<?php

namespace App\Domain\Payments;

use App\Domain\Payments\Gateway\CheckoutSessionState;
use App\Domain\Payments\Gateway\CheckoutSessionStatus;
use Illuminate\Support\Str;

/**
 * Eventi del checkout simulato (3.3): traduce l'azione dell'ospite sulla pagina "Pagamento simulato"
 * nell'evento che Stripe invierebbe al webhook e lo passa a PaymentEventHandler, lo stesso codice che
 * gestirà i webhook veri in M4.
 */
final class SimulatedPaymentEvents
{
    public function __construct(private readonly PaymentEventHandler $handler) {}

    public function dispatch(string $accountId, CheckoutSessionState $state): void
    {
        $type = match (true) {
            $state->status === CheckoutSessionStatus::COMPLETE => PaymentProviderEvent::CHECKOUT_COMPLETED,
            $state->paymentFailed => PaymentProviderEvent::PAYMENT_FAILED,
            default => null,
        };

        if ($type === null) {
            return;
        }

        $this->handler->handle(new PaymentProviderEvent(
            id: 'evt_fake_'.Str::lower((string) Str::ulid()),
            type: $type,
            accountId: $accountId,
            checkoutSessionId: $state->id,
            metadata: $state->metadata,
        ));
    }
}
