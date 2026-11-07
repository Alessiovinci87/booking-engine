<?php

namespace App\Domain\Payments\Gateway;

use App\Domain\Payments\Gateway\Exceptions\PaymentDeclined;
use App\Domain\Payments\Gateway\Exceptions\PaymentProviderRejected;
use App\Domain\Payments\Gateway\Exceptions\PaymentProviderUnavailable;
use App\Domain\Payments\Gateway\Exceptions\SessionAlreadyCompleted;

/**
 * Fornitore di pagamento (05, ADR-0006): implementazioni StripePaymentGateway (M4) e
 * FakePaymentGateway (test e demo locali). Le chiamate avvengono sempre fuori dalle transazioni del
 * database; ogni chiamata che modifica qualcosa porta un'idempotency key derivata da un nostro ID
 * (`checkout:{payment_id}`, `expire:{payment_id}`, `capture:{payment_id}`, `cancel:{payment_id}`,
 * `refund:{refund_id}`).
 *
 * Errori:
 * - PaymentProviderUnavailable: rete, timeout o errore del fornitore; l'esito è sconosciuto e la
 *   chiamata si ripete con la stessa idempotency key (I9);
 * - PaymentDeclined: rifiuto definitivo (capture, rimborso);
 * - SessionAlreadyCompleted: expireSession su una sessione già pagata o autorizzata (T6);
 * - PaymentProviderRejected: richiesta rifiutata per un motivo non transitorio. Su
 *   createCheckoutSession significa che la sessione non è stata creata (05: il job rilascia).
 */
interface PaymentGateway
{
    /**
     * Crea la sessione di checkout ospitata. La stessa idempotency key restituisce la stessa sessione:
     * è il meccanismo di recupero di 05 quando il passo 2 o 3 non si completa.
     *
     * @throws PaymentProviderUnavailable
     * @throws PaymentProviderRejected
     */
    public function createCheckoutSession(CheckoutSessionRequest $request): CheckoutSession;

    /**
     * Rilettura della sessione e del PaymentIntent collegato (05: si applica solo ciò che si legge).
     *
     * @throws PaymentProviderUnavailable
     */
    public function retrieveSession(string $accountId, string $sessionId): CheckoutSessionState;

    /**
     * Chiude una sessione aperta. Una sessione già scaduta si considera chiusa (05).
     *
     * @throws SessionAlreadyCompleted
     * @throws PaymentProviderUnavailable
     */
    public function expireSession(string $accountId, string $sessionId, string $idempotencyKey): CheckoutSessionState;

    /**
     * Incassa un'autorizzazione (`requires_capture`).
     *
     * @throws PaymentDeclined
     * @throws PaymentProviderUnavailable
     */
    public function capture(string $accountId, string $paymentIntentId, int $amountCents, string $idempotencyKey): CaptureResult;

    /**
     * Annulla un'autorizzazione non incassata.
     *
     * @throws PaymentProviderUnavailable
     */
    public function cancelAuthorization(string $accountId, string $paymentIntentId, string $idempotencyKey): void;

    /**
     * Rimborso (anche parziale) di un pagamento incassato. I metadata contengono solo ID interni.
     *
     * @param  array<string, string>  $metadata
     *
     * @throws PaymentDeclined
     * @throws PaymentProviderUnavailable
     */
    public function refund(string $accountId, string $paymentIntentId, int $amountCents, string $idempotencyKey, array $metadata = []): RefundResult;

    /**
     * Rilettura di un rimborso (refund.updated, riconciliazione dei rimborsi PENDING).
     *
     * @throws PaymentProviderUnavailable
     */
    public function retrieveRefund(string $accountId, string $refundId): RefundResult;

    /**
     * Rilettura di un PaymentIntent (riconciliazione di AUTHORIZED e CAPTURE_PENDING, 05).
     *
     * @throws PaymentProviderUnavailable
     */
    public function retrievePaymentIntent(string $accountId, string $paymentIntentId): PaymentIntentState;

    /**
     * Rilettura di una contestazione (charge.dispute.*).
     *
     * @throws PaymentProviderUnavailable
     */
    public function retrieveDispute(string $accountId, string $disputeId): DisputeState;
}
