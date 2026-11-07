<?php

namespace App\Domain\Payments;

use App\Domain\Inventory\Enums\OccupancyTempReason;
use App\Domain\Inventory\InventoryService;
use App\Domain\Payments\Enums\PaymentPurpose;
use App\Domain\Payments\Enums\PaymentStatus;
use App\Domain\Payments\Gateway\CheckoutSession;
use App\Domain\Payments\Gateway\CheckoutSessionRequest;
use App\Domain\Payments\Gateway\Exceptions\PaymentProviderRejected;
use App\Domain\Payments\Gateway\Exceptions\PaymentProviderUnavailable;
use App\Domain\Payments\Gateway\Exceptions\SessionAlreadyCompleted;
use App\Domain\Payments\Gateway\PaymentGateway;
use App\Domain\Payments\Models\Payment;
use App\Domain\Payments\Models\StripeAccount;
use App\Domain\Payments\StateMachines\PaymentStateMachine;
use App\Domain\Reservations\CreatedReservation;
use App\Domain\Reservations\Enums\ReservationStatus;
use App\Domain\Reservations\Models\Guest;
use App\Domain\Reservations\Models\Reservation;
use App\Domain\Reservations\NewReservation;
use App\Domain\Reservations\StateMachines\ReservationStateMachine;
use App\Domain\Tenancy\Exceptions\BusinessRuleViolation;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\Outbox\Outbox;
use App\Domain\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;

/**
 * Checkout in tre passi (05, "Creazione del checkout"), con le chiamate al fornitore sempre fuori
 * dalle transazioni:
 *
 * 1. transazione: T1 (occupazione CHECKOUT con scadenza provvisoria, Payment PENDING);
 * 2. fornitore: sessione con idempotency key `checkout:{payment_id}`;
 * 3. transazione: checkout_session_id e checkout_expires_at restituiti, occupazione allineata a
 *    checkout_expires_at + 5 min.
 *
 * La richiesta al fornitore è deterministica (scadenza derivata dalla creazione del Payment): rifatta
 * con la stessa chiave, recupera la sessione eventualmente già creata. Serve al job di rilascio
 * (05 "Rilascio delle occupazioni con sessione") quando i passi 2 o 3 non si sono completati.
 */
final class CheckoutService
{
    public function __construct(
        private readonly TenantContext $tenants,
        private readonly PaymentGateway $gateway,
        private readonly ReservationStateMachine $reservations,
        private readonly PaymentStateMachine $payments,
        private readonly InventoryService $inventory,
        private readonly CheckoutOutcomes $outcomes,
        private readonly Outbox $outbox,
    ) {}

    /**
     * Avvio della prenotazione dalla pagina ospite (07 POST /public/{t}/{p}/reservations).
     *
     * @return array{created: CreatedReservation, session: CheckoutSession}
     */
    public function start(string $tenantId, NewReservation $data): array
    {
        $created = $this->tenants->run($tenantId, function () use ($data): CreatedReservation {
            $account = StripeAccount::query()->first();

            if ($account === null || ! $account->charges_enabled) {
                throw BusinessRuleViolation::make(422, 'PAYMENTS_NOT_ENABLED');
            }

            return $this->reservations->startCheckout($data, $account->stripe_account_id);
        });

        $payment = $created->payment ?? throw new \LogicException('Checkout senza pagamento.');

        return ['created' => $created, 'session' => $this->openSession($tenantId, $payment->id)];
    }

    /**
     * Passi 2 e 3 per un Payment PENDING: crea (o recupera) la sessione e allinea le scadenze.
     *
     * @throws PaymentProviderUnavailable
     */
    public function openSession(string $tenantId, string $paymentId): CheckoutSession
    {
        $request = $this->tenants->run($tenantId, fn (): CheckoutSessionRequest => $this->sessionRequest(Payment::query()->findOrFail($paymentId)));

        try {
            $session = $this->gateway->createCheckoutSession($request);
        } catch (PaymentProviderUnavailable|PaymentProviderRejected $e) {
            // L'occupazione resta con la scadenza provvisoria: il job di rilascio recupera o rilascia.
            Log::warning('payments.checkout_session_unavailable', ['payment_id' => $paymentId, 'error' => class_basename($e)]);

            throw BusinessRuleViolation::make(503, 'PAYMENT_PROVIDER_UNAVAILABLE');
        }

        $this->tenants->run($tenantId, function () use ($paymentId, $session): void {
            $payment = Payment::query()->lockForUpdate()->findOrFail($paymentId);

            if ($payment->checkout_session_id === null) {
                $this->payments->attachSession($payment, $session->id, $session->expiresAt);
            }

            $reservation = Reservation::query()->findOrFail($payment->reservation_id);

            if ($reservation->status === ReservationStatus::AWAITING_PAYMENT) {
                $this->inventory->setExpiry($reservation, $session->expiresAt->addMinutes((int) config('payments.occupancy_grace_minutes')));
            }

            if ($payment->purpose === PaymentPurpose::RECOVERY) {
                $this->outbox->enqueue('reservation.recovery_link', 'reservation.recovery_link:'.$payment->id, [
                    'reservation_id' => $reservation->id, 'payment_id' => $payment->id,
                ]);
            }
        });

        return $session;
    }

    /**
     * T15 dal pannello: nuovo Payment RECOVERY e sessione con scadenza = recovery_deadline.
     */
    public function sendRecoveryLink(string $tenantId, string $reservationId, int $deadlineHours): CheckoutSession
    {
        $payment = $this->tenants->run($tenantId, function () use ($reservationId, $deadlineHours): Payment {
            $account = StripeAccount::query()->first() ?? throw BusinessRuleViolation::make(422, 'PAYMENTS_NOT_ENABLED');

            return $this->reservations->sendRecoveryLink(Reservation::query()->findOrFail($reservationId), $deadlineHours, $account->stripe_account_id);
        });

        return $this->openSession($tenantId, $payment->id);
    }

    /**
     * Rilascio di un'occupazione di checkout o di recupero scaduta (05, I16): la sessione si chiude con
     * expire (o si recupera con la stessa idempotency key se non risulta salvata); se risulta già
     * completata si prosegue con il pagamento (T6); se il fornitore non risponde non si rilascia.
     *
     * @return 'released'|'completed'|'retry'|'skipped'
     */
    public function releaseExpired(string $tenantId, string $reservationId): string
    {
        $loaded = $this->tenants->run($tenantId, function () use ($reservationId): ?array {
            $reservation = Reservation::query()->find($reservationId);

            if ($reservation === null || $reservation->status !== ReservationStatus::AWAITING_PAYMENT) {
                return null;
            }

            $occupancy = $this->inventory->activeFor($reservation);
            $reason = $occupancy->temp_reason;

            if (! in_array($reason, [OccupancyTempReason::CHECKOUT, OccupancyTempReason::PAYMENT_RECOVERY], true)
                || $occupancy->expires_at === null || $occupancy->expires_at->isFuture()) {
                return null;
            }

            $payment = Payment::query()->where('reservation_id', $reservation->id)
                ->where('status', PaymentStatus::PENDING->value)
                ->orderByDesc('created_at')->orderByDesc('id')->first();

            return $payment === null ? null : [$payment, $payment->checkout_session_id === null ? $this->sessionRequest($payment) : null];
        });

        if ($loaded === null) {
            return 'skipped';
        }

        /** @var Payment $payment */
        [$payment, $recovery] = $loaded;
        $sessionId = $payment->checkout_session_id;
        $account = (string) $payment->stripe_account_id;

        try {
            if ($sessionId === null && $recovery instanceof CheckoutSessionRequest) {
                // Recupero tramite idempotency key: se la sessione non esisteva, nasce ora e si chiude subito.
                try {
                    $sessionId = $this->gateway->createCheckoutSession($recovery)->id;
                } catch (PaymentProviderRejected) {
                    // Il fornitore rifiuta la richiesta (per esempio expires_at ormai passato): con la
                    // stessa chiave una sessione già creata sarebbe restituita, quindi non è mai esistita.
                    $this->releaseWithoutSession($tenantId, $payment->id);

                    return 'released';
                }

                $this->tenants->run($tenantId, fn () => $this->payments->attachSession(
                    Payment::query()->lockForUpdate()->findOrFail($payment->id), $sessionId,
                    $this->gateway->retrieveSession($account, $sessionId)->expiresAt,
                ));
            }

            $this->gateway->expireSession($account, (string) $sessionId, 'expire:'.$payment->id);
        } catch (SessionAlreadyCompleted) {
            $this->outcomes->process($tenantId, $payment->id);

            return 'completed';
        } catch (PaymentProviderUnavailable) {
            Log::warning('payments.release_retry', ['payment_id' => $payment->id]);

            return 'retry';
        }

        $this->tenants->run($tenantId, function () use ($payment): void {
            $current = Payment::query()->findOrFail($payment->id);
            $reservation = Reservation::query()->findOrFail($current->reservation_id);

            if ($reservation->status === ReservationStatus::AWAITING_PAYMENT) {
                $this->reservations->checkoutExpired($reservation, $current);
            }
        });

        return 'released';
    }

    private function releaseWithoutSession(string $tenantId, string $paymentId): void
    {
        $this->tenants->run($tenantId, function () use ($paymentId): void {
            $current = Payment::query()->lockForUpdate()->findOrFail($paymentId);
            $reservation = Reservation::query()->findOrFail($current->reservation_id);

            if ($reservation->status === ReservationStatus::AWAITING_PAYMENT && $current->checkout_session_id === null) {
                $this->reservations->checkoutExpired($reservation, $current);
            }
        });
    }

    /**
     * Richiesta di sessione deterministica per il Payment (stessa chiave, stessi parametri). La scadenza
     * è creazione del Payment + 30 minuti + un margine (ADR-0012): Stripe accetta expires_at solo da 30
     * minuti dopo la creazione della sessione, che avviene qualche istante dopo il passo 1.
     */
    private function sessionRequest(Payment $payment): CheckoutSessionRequest
    {
        $reservation = Reservation::query()->with('unit.property')->findOrFail($payment->reservation_id);
        $guest = Guest::query()->findOrFail($reservation->guest_id);
        $tenantSlug = Tenant::query()->whereKey($reservation->tenant_id)->value('slug');
        $property = $reservation->unit->property;

        $expiresAt = $payment->purpose === PaymentPurpose::RECOVERY && $reservation->recovery_deadline !== null
            ? $reservation->recovery_deadline
            : CarbonImmutable::parse($payment->created_at)->addMinutes((int) config('payments.checkout_session_minutes'))
                ->addSeconds((int) config('payments.checkout_session_margin_seconds'));

        $return = rtrim((string) config('app.url'), '/')."/p/{$tenantSlug}/{$property->slug}/prenotazione/{$reservation->code}";

        return new CheckoutSessionRequest(
            accountId: (string) $payment->stripe_account_id,
            idempotencyKey: 'checkout:'.$payment->id,
            amountCents: $payment->amount_cents,
            currency: $reservation->currency,
            captureMethod: $payment->capture_method,
            expiresAt: $expiresAt->startOfSecond(),
            description: $property->name.' · '.$reservation->unit->name,
            successUrl: $return.'?session={CHECKOUT_SESSION_ID}',
            cancelUrl: $return.'?cancelled=1',
            locale: $reservation->guest_locale,
            customerEmail: $guest->anonymized_at === null ? $guest->email : null,
            metadata: ['reservation_id' => $reservation->id, 'payment_id' => $payment->id, 'tenant_id' => $reservation->tenant_id],
        );
    }
}
