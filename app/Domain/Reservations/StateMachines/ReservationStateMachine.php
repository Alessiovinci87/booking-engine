<?php

namespace App\Domain\Reservations\StateMachines;

use App\Domain\Inventory\Enums\BookingMode;
use App\Domain\Inventory\Enums\ConflictStatus;
use App\Domain\Inventory\Enums\OccupancyPhase;
use App\Domain\Inventory\Enums\OccupancyTempReason;
use App\Domain\Inventory\Enums\PaymentMode;
use App\Domain\Inventory\InventoryService;
use App\Domain\Inventory\Models\InventoryConflict;
use App\Domain\Payments\Enums\CaptureMethod;
use App\Domain\Payments\Enums\OfflineMethod;
use App\Domain\Payments\Enums\PaymentPurpose;
use App\Domain\Payments\Enums\PaymentStatus;
use App\Domain\Payments\Jobs\CancelAuthorizationJob;
use App\Domain\Payments\Jobs\CapturePaymentJob;
use App\Domain\Payments\Jobs\ProcessRefundJob;
use App\Domain\Payments\Models\Payment;
use App\Domain\Payments\StateMachines\PaymentStateMachine;
use App\Domain\Payments\StateMachines\RefundStateMachine;
use App\Domain\Pricing\Calculator\PriceLine;
use App\Domain\Pricing\Calculator\PriceQuote;
use App\Domain\Reservations\CancellationRefund;
use App\Domain\Reservations\CreatedReservation;
use App\Domain\Reservations\Enums\ConfirmationKind;
use App\Domain\Reservations\Enums\ReservationChannel;
use App\Domain\Reservations\Enums\ReservationEvent;
use App\Domain\Reservations\Enums\ReservationStatus;
use App\Domain\Reservations\Exceptions\IllegalTransition;
use App\Domain\Reservations\Models\Guest;
use App\Domain\Reservations\Models\Reservation;
use App\Domain\Reservations\Models\ReservationChange;
use App\Domain\Reservations\Models\ReservationPriceLine;
use App\Domain\Reservations\NewReservation;
use App\Domain\Reservations\ReservationSnapshots;
use App\Domain\Reservations\StayRange;
use App\Domain\Tenancy\Audit\AuditLogger;
use App\Domain\Tenancy\Exceptions\BusinessRuleViolation;
use App\Domain\Tenancy\Exceptions\MissingTenantContext;
use App\Domain\Tenancy\Outbox\Outbox;
use App\Domain\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Macchina a stati della prenotazione (03, sezione 1): un metodo per evento, le coppie ammesse in
 * ReservationTransitions. Ogni transizione avviene in una transazione con la riga della prenotazione
 * bloccata (FOR UPDATE) e porta con sé, atomicamente, le operazioni sull'inventario (InventoryService,
 * I15), sul pagamento, l'audit e i messaggi in outbox (email: inviate dopo, dal dispatcher).
 *
 * Le chiamate al fornitore di pagamento non avvengono mai qui: i job (capture, annullo, rimborso) si
 * accodano dopo il commit; la sessione di checkout la crea CheckoutService fuori dalla transazione.
 * Serve un contesto tenant attivo (richiesta o job).
 */
final class ReservationStateMachine
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly InventoryService $inventory,
        private readonly PaymentStateMachine $payments,
        private readonly RefundStateMachine $refunds,
        private readonly AuditLogger $audit,
        private readonly Outbox $outbox,
        private readonly ReservationSnapshots $snapshots,
    ) {}

    // ------------------------------------------------------------------ creazione

    /**
     * T1 (05, passo 1): occupazione CHECKOUT con scadenza provvisoria, prenotazione AWAITING_PAYMENT e
     * Payment PENDING. Capture manuale per le unità REQUEST (05: deriva dalla prenotazione).
     */
    public function startCheckout(NewReservation $data, string $stripeAccountId): CreatedReservation
    {
        if ($data->channel === ReservationChannel::MANUAL) {
            throw IllegalTransition::of('reservation', 'none', 'start_checkout_manual_channel');
        }

        $expiresAt = CarbonImmutable::now()->addMinutes((int) config('payments.provisional_hold_minutes'));

        return $this->create($data, ReservationEvent::START_CHECKOUT, OccupancyPhase::TEMPORARY, OccupancyTempReason::CHECKOUT, $expiresAt,
            function (Reservation $reservation) use ($stripeAccountId): Payment {
                $request = $reservation->booking_mode_snapshot === BookingMode::REQUEST;
                $purpose = $reservation->payment_mode_snapshot === PaymentMode::FULL ? PaymentPurpose::FULL : PaymentPurpose::DEPOSIT;

                return $this->payments->createPending($reservation, $purpose, $reservation->deposit_due_cents,
                    $request ? CaptureMethod::MANUAL : CaptureMethod::AUTOMATIC, $stripeAccountId);
            });
    }

    /**
     * T2: prenotazione del gestore già CONFIRMED con conferma amministrativa, anche senza pagamento
     * (stato economico UNPAID, I11); pagamento OFFLINE facoltativo.
     */
    public function createManual(NewReservation $data, ?int $offlineAmountCents = null, ?OfflineMethod $offlineMethod = null): CreatedReservation
    {
        // I11: la conferma amministrativa vale solo per le prenotazioni del gestore.
        if ($data->channel !== ReservationChannel::MANUAL) {
            throw IllegalTransition::of('reservation', 'none', 'create_manual_online_channel');
        }

        return $this->create($data, ReservationEvent::CREATE_MANUAL, OccupancyPhase::FIRM, null, null,
            function (Reservation $reservation) use ($offlineAmountCents, $offlineMethod): ?Payment {
                if ($offlineAmountCents === null || $offlineMethod === null) {
                    return null;
                }

                return $this->payments->createOffline($reservation, $offlineAmountCents, $offlineMethod);
            });
    }

    // ------------------------------------------------------------------ esiti del pagamento online

    /** T3 (anche T6): pagamento riuscito di una prenotazione INSTANT o di un recupero. */
    public function paymentSucceeded(Reservation $reservation, Payment $payment, ?string $paymentIntentId, int $capturedCents): Reservation
    {
        return $this->transition($reservation, ReservationEvent::PAYMENT_SUCCEEDED, function (Reservation $r) use ($payment, $paymentIntentId, $capturedCents): void {
            if ($r->booking_mode_snapshot !== BookingMode::INSTANT && $payment->purpose !== PaymentPurpose::RECOVERY) {
                throw IllegalTransition::of('reservation', $r->status->value, 'payment_succeeded_without_instant_or_recovery');
            }

            $this->inventory->activeFor($r);
            $this->payments->markSucceeded($payment, $paymentIntentId, $capturedCents);
            $this->confirmOnline($r);
        });
    }

    /** T4 (anche T6): autorizzazione riuscita di una richiesta REQUEST. */
    public function authorizationSucceeded(Reservation $reservation, Payment $payment, string $paymentIntentId, ?CarbonImmutable $captureBefore): Reservation
    {
        return $this->transition($reservation, ReservationEvent::AUTHORIZATION_SUCCEEDED, function (Reservation $r) use ($payment, $paymentIntentId, $captureBefore): void {
            if ($r->booking_mode_snapshot !== BookingMode::REQUEST || $payment->purpose === PaymentPurpose::RECOVERY) {
                throw IllegalTransition::of('reservation', $r->status->value, 'authorization_without_request');
            }

            $this->inventory->activeFor($r);
            $this->payments->markAuthorized($payment, $paymentIntentId, $captureBefore);

            $deadline = CarbonImmutable::now()->addHours((int) config('payments.approval_window_hours'));

            if ($captureBefore !== null && $captureBefore->subHours(6)->lessThan($deadline)) {
                $deadline = $captureBefore->subHours(6);
            }

            $r->approval_deadline = $deadline;
            $this->inventory->setTemporaryReason($r, OccupancyTempReason::AWAITING_APPROVAL, $deadline);
            $this->notify($r, 'reservation.awaiting_approval');
            $this->notify($r, 'reservation.approval_requested');
        });
    }

    /** T5: il job di rilascio ha chiuso la sessione (o era già scaduta). */
    public function checkoutExpired(Reservation $reservation, Payment $payment): Reservation
    {
        return $this->transition($reservation, ReservationEvent::CHECKOUT_EXPIRED, function (Reservation $r) use ($payment): void {
            if ($payment->status === PaymentStatus::PENDING) {
                $this->payments->markExpired($payment);
            }

            $this->inventory->release($r, 'checkout_expired');
            $this->notify($r, 'reservation.expired');
        });
    }

    /**
     * T7 (I4): pagamento o autorizzazione arrivati dopo il rilascio. La prenotazione resta nello stato
     * terminale; il denaro torna all'ospite (rimborso o annullo dopo il commit), con email di scuse.
     */
    public function latePayment(Reservation $reservation, Payment $payment, string $paymentIntentId, bool $authorizedOnly, int $capturedCents, ?CarbonImmutable $captureBefore): Reservation
    {
        return $this->transition($reservation, ReservationEvent::LATE_PAYMENT, function (Reservation $r) use ($payment, $paymentIntentId, $authorizedOnly, $capturedCents, $captureBefore): void {
            if ($authorizedOnly) {
                $this->payments->markAuthorized($payment, $paymentIntentId, $captureBefore);
            } else {
                $this->payments->markSucceeded($payment, $paymentIntentId, $capturedCents);
            }

            if ($authorizedOnly) {
                $this->afterCommit(fn () => CancelAuthorizationJob::dispatch($r->tenant_id, $payment->id));
            } else {
                $refund = $this->refunds->createPending($payment, $capturedCents, null, null);
                $this->afterCommit(fn () => ProcessRefundJob::dispatch($r->tenant_id, $refund->id));
            }

            $this->audit->record('reservation.late_payment', 'reservation', $r->id, ['payment_id' => $payment->id, 'authorized_only' => $authorizedOnly]);
            $this->notify($r, 'reservation.late_payment', $payment->id);
        });
    }

    /** T8: pagamento fallito definitivo, dopo la chiusura della sessione. */
    public function paymentFailed(Reservation $reservation, Payment $payment, ?string $failureCode): Reservation
    {
        return $this->transition($reservation, ReservationEvent::PAYMENT_FAILED, function (Reservation $r) use ($payment, $failureCode): void {
            if ($payment->status === PaymentStatus::PENDING) {
                $this->payments->markFailed($payment, $failureCode);
            }

            $this->inventory->release($r, 'payment_failed');
            $this->notify($r, 'reservation.payment_failed');
        });
    }

    // ------------------------------------------------------------------ approvazione e capture

    /** T9: approvazione del gestore; la capture parte dopo il commit con idempotency key. */
    public function approve(Reservation $reservation): Reservation
    {
        return $this->transition($reservation, ReservationEvent::APPROVE, function (Reservation $r): void {
            if ($r->approval_deadline === null || ! CarbonImmutable::now()->lessThan($r->approval_deadline)) {
                throw IllegalTransition::of('reservation', $r->status->value, 'approval_deadline_passed');
            }

            $conflicts = InventoryConflict::query()
                ->where('unit_id', $r->unit_id)
                ->whereIn('status', [ConflictStatus::OPEN->value, ConflictStatus::INVESTIGATING->value])
                ->whereRaw('stay && ?::daterange', [$r->stay->toPostgres()])
                ->pluck('id')->all();

            if ($conflicts !== []) {
                throw BusinessRuleViolation::make(409, 'CONFLICT_OPEN', ['conflict_ids' => $conflicts]);
            }

            $payment = $this->onlinePayment($r, PaymentStatus::AUTHORIZED);
            $this->payments->requestCapture($payment);
            $this->inventory->setTemporaryReason($r, OccupancyTempReason::CAPTURING, null);
            $this->afterCommit(fn () => CapturePaymentJob::dispatch($r->tenant_id, $payment->id));
        });
    }

    /**
     * T10: rifiuto del gestore; annullo dell'autorizzazione dopo il commit, con retry. Il motivo
     * facoltativo resta sulla prenotazione (cancel_reason), mai nell'audit (testo libero, 09).
     */
    public function reject(Reservation $reservation, ?string $reason = null): Reservation
    {
        return $this->transition($reservation, ReservationEvent::REJECT, function (Reservation $r) use ($reason): void {
            $r->cancel_reason = $reason;
            $this->inventory->release($r, 'rejected');
            $this->cancelAuthorizationLater($r);
            $this->notify($r, 'reservation.rejected');
        });
    }

    /** T11: approval_deadline superata (job). */
    public function approvalExpired(Reservation $reservation): Reservation
    {
        return $this->transition($reservation, ReservationEvent::APPROVAL_EXPIRED, function (Reservation $r): void {
            if ($r->approval_deadline !== null && CarbonImmutable::now()->lessThan($r->approval_deadline)) {
                throw IllegalTransition::of('reservation', $r->status->value, 'approval_deadline_not_passed');
            }

            $this->inventory->release($r, 'approval_expired');
            $this->cancelAuthorizationLater($r);
            $this->notify($r, 'reservation.approval_expired');
            $this->notify($r, 'reservation.approval_expired_manager');
        });
    }

    /** T12: capture riuscita. */
    public function captureSucceeded(Reservation $reservation, Payment $payment, int $capturedCents): Reservation
    {
        return $this->transition($reservation, ReservationEvent::CAPTURE_SUCCEEDED, function (Reservation $r) use ($payment, $capturedCents): void {
            $this->payments->markSucceeded($payment, null, $capturedCents);
            $this->confirmOnline($r);
        });
    }

    /** T13: capture fallita definitiva; le date restano occupate fino alla decisione (24 h). */
    public function captureFailed(Reservation $reservation, Payment $payment, string $failureCode): Reservation
    {
        return $this->transition($reservation, ReservationEvent::CAPTURE_FAILED, function (Reservation $r) use ($payment, $failureCode): void {
            $this->payments->markCaptureFailed($payment, $failureCode);
            $deadline = CarbonImmutable::now()->addHours(24);
            $r->decision_deadline = $deadline;
            $this->inventory->setTemporaryReason($r, OccupancyTempReason::CAPTURE_FAILED, $deadline);
            $this->notify($r, 'reservation.capture_failed');
            // Promemoria al gestore a 12 h dalla scadenza (03 "Note"): parte solo se la prenotazione è
            // ancora CAPTURE_FAILED quando il messaggio diventa disponibile (SendReservationEmail).
            $this->outbox->enqueue('reservation.capture_failed_reminder', 'reservation.capture_failed_reminder:'.$r->id, [
                'reservation_id' => $r->id,
            ], $deadline->subHours(12));
        });
    }

    /** T14: esito della capture sconosciuto; nessun rilascio, decide la riconciliazione. */
    public function captureUnknown(Reservation $reservation): Reservation
    {
        return $this->transition($reservation, ReservationEvent::CAPTURE_UNKNOWN, fn () => null);
    }

    /**
     * T15: link di recupero dopo capture fallita (un solo recupero per prenotazione). Restituisce il
     * nuovo Payment RECOVERY: la sessione la crea CheckoutService con expires_at = recovery_deadline.
     */
    public function sendRecoveryLink(Reservation $reservation, int $deadlineHours, string $stripeAccountId): Payment
    {
        if ($deadlineHours < 1 || $deadlineHours > 24) {
            throw new LogicException('La durata del link di recupero va da 1 a 24 ore (03).');
        }

        $payment = null;

        $this->transition($reservation, ReservationEvent::SEND_RECOVERY_LINK, function (Reservation $r) use ($deadlineHours, $stripeAccountId, &$payment): void {
            $now = CarbonImmutable::now();

            if ($r->decision_deadline === null || ! $now->lessThan($r->decision_deadline)) {
                throw IllegalTransition::of('reservation', $r->status->value, 'decision_deadline_passed');
            }

            if (Payment::query()->where('reservation_id', $r->id)->where('purpose', PaymentPurpose::RECOVERY->value)->exists()) {
                throw IllegalTransition::of('reservation', $r->status->value, 'recovery_already_sent');
            }

            // Entro il giorno prima del check-in (03): non oltre la mezzanotte del giorno di arrivo.
            $deadline = $now->addHours($deadlineHours);
            $arrival = CarbonImmutable::parse($r->stay->checkIn->toDateString(), $r->unit->property->timezone)->utc();

            if ($arrival->lessThan($deadline)) {
                $deadline = $arrival;
            }

            if (! $now->addMinutes(30)->lessThanOrEqualTo($deadline)) {
                throw IllegalTransition::of('reservation', $r->status->value, 'recovery_too_close_to_arrival');
            }

            $r->recovery_deadline = $deadline;
            $payment = $this->payments->createPending($r, PaymentPurpose::RECOVERY, $r->deposit_due_cents, CaptureMethod::AUTOMATIC, $stripeAccountId);
            $this->inventory->setTemporaryReason($r, OccupancyTempReason::PAYMENT_RECOVERY,
                $deadline->addMinutes((int) config('payments.occupancy_grace_minutes')));
        });

        if (! $payment instanceof Payment) {
            throw new LogicException('Pagamento di recupero non creato.');
        }

        return $payment;
    }

    /** T16: il gestore rilascia le date o il job lo fa alla decision_deadline. */
    public function releaseDates(Reservation $reservation): Reservation
    {
        return $this->transition($reservation, ReservationEvent::RELEASE_DATES, function (Reservation $r): void {
            $this->inventory->release($r, 'capture_failed_released');
            $this->notify($r, 'reservation.dates_released');
        });
    }

    // ------------------------------------------------------------------ operazioni del gestore

    /**
     * T17: cancellazione. Date libere subito, Refund PENDING nella stessa transazione se l'importo è
     * maggiore di zero, chiamata al fornitore dopo il commit; l'email dice "rimborso avviato" (I20).
     */
    public function cancel(Reservation $reservation, CancellationRefund $refund, ?string $reason, ?string $userId): Reservation
    {
        return $this->transition($reservation, ReservationEvent::CANCEL, function (Reservation $r) use ($refund, $reason, $userId): void {
            $r->cancelled_at = CarbonImmutable::now();
            $r->cancel_reason = $reason;
            $this->inventory->release($r, 'cancelled');

            foreach ($refund->allocate($r) as $paymentId => $amount) {
                $payment = Payment::query()->findOrFail($paymentId);
                $created = $this->refunds->createPending($payment, $amount, null, $userId);
                $this->afterCommit(fn () => ProcessRefundJob::dispatch($r->tenant_id, $created->id));
            }

            $this->notify($r, 'reservation.cancelled');
        });
    }

    /**
     * T18: modifica di date e ospiti sulla stessa occupazione (InventoryService::move) con
     * ReservationChange. Il nuovo prezzo arriva dal server; le differenze economiche si gestiscono a
     * parte (pagamenti offline, link di saldo, rimborsi).
     */
    public function modify(Reservation $reservation, StayRange $stay, int $adults, int $children, PriceQuote $price, ?string $note, ?string $userId): Reservation
    {
        return $this->transition($reservation, ReservationEvent::MODIFY, function (Reservation $r) use ($stay, $adults, $children, $price, $note, $userId): void {
            $change = new ReservationChange;
            $change->reservation_id = $r->id;
            $change->changed_by_user_id = $userId;
            $change->previous_stay = $r->stay;
            $change->new_stay = $stay;
            $change->previous_adults = $r->adults;
            $change->new_adults = $adults;
            $change->previous_children = $r->children;
            $change->new_children = $children;
            $change->previous_contract_total_cents = $r->contract_total_cents;
            $change->new_contract_total_cents = $price->totalCents;
            $change->note = $note;
            $change->occurred_at = CarbonImmutable::now();
            $change->save();

            if (! $stay->equals($r->stay)) {
                $this->inventory->move($r, $stay);
            }

            $r->stay = $stay;
            $r->adults = $adults;
            $r->children = $children;
            $this->applyPrice($r, $price);
            ReservationPriceLine::query()->where('reservation_id', $r->id)->delete();
            $this->savePriceLines($r, $price);

            $this->audit->record('reservation.changed', 'reservation_change', $change->id, [
                'reservation_id' => $r->id, 'stay' => ['from' => (string) $change->previous_stay, 'to' => (string) $stay],
                'adults' => ['from' => $change->previous_adults, 'to' => $adults], 'children' => ['from' => $change->previous_children, 'to' => $children],
                'contract_total_cents' => ['from' => $change->previous_contract_total_cents, 'to' => $price->totalCents],
                'note' => $note === null ? null : '[personal]',
            ]);
        });
    }

    // ------------------------------------------------------------------ interni

    /**
     * @param  Closure(Reservation): ?Payment  $afterCreate
     */
    private function create(NewReservation $data, ReservationEvent $event, OccupancyPhase $phase, ?OccupancyTempReason $reason, ?CarbonImmutable $expiresAt, Closure $afterCreate): CreatedReservation
    {
        $this->assertTenant();
        $transition = ReservationTransitions::find(null, $event) ?? throw IllegalTransition::of('reservation', null, $event->value);
        $token = bin2hex(random_bytes(32));
        $payment = null;

        $reservation = DB::transaction(function () use ($data, $transition, $phase, $reason, $expiresAt, $token, $afterCreate, &$payment): Reservation {
            $created = null;

            $this->inventory->acquireForReservation($data->unit, $data->stay, $phase, $reason, $expiresAt, function () use ($data, $transition, $token, &$created): Reservation {
                $guest = new Guest;
                $guest->first_name = $data->guest->firstName;
                $guest->last_name = $data->guest->lastName;
                $guest->email = $data->guest->email;
                $guest->phone = $data->guest->phone;
                $guest->country_code = strtoupper($data->guest->countryCode);
                $guest->locale = $data->guest->locale;
                $guest->notes = $data->guest->notes;
                $guest->save();

                $r = new Reservation;
                $r->tenant_id = $data->unit->tenant_id;
                $r->unit_id = $data->unit->id;
                $r->guest_id = $guest->id;
                $r->quote_id = $data->quoteId;
                $r->code = self::newCode();
                $r->channel = $data->channel;
                $r->stay = $data->stay;
                $r->adults = $data->adults;
                $r->children = $data->children;
                $r->status = $transition['to'];
                $r->booking_mode_snapshot = $data->unit->booking_mode;
                $r->payment_mode_snapshot = $data->unit->payment_mode;
                $r->cancellation_policy_snapshot = $this->snapshots->cancellationPolicy($data->unit);
                $r->currency = $data->price->currency;
                $r->guest_locale = $data->guest->locale;
                $r->public_access_token_hash = self::hashToken($token);
                $this->applyPrice($r, $data->price);

                if ($transition['to'] === ReservationStatus::CONFIRMED) {
                    $r->confirmation_kind = ConfirmationKind::ADMINISTRATIVE;
                    $r->confirmed_at = CarbonImmutable::now();
                }

                $r->save();
                $this->savePriceLines($r, $data->price);

                return $created = $r;
            });

            if (! $created instanceof Reservation) {
                throw new LogicException('Prenotazione non creata.');
            }

            $payment = $afterCreate($created);

            $this->audit->record('reservation.'.$transition['event']->value, 'reservation', $created->id, [
                'transition' => $transition['id'], 'status' => ['from' => null, 'to' => $created->status->value],
                'unit_id' => $created->unit_id, 'stay' => (string) $created->stay, 'channel' => $created->channel->value,
                'contract_total_cents' => $created->contract_total_cents,
            ]);

            return $created;
        });

        return new CreatedReservation($reservation, $token, $payment);
    }

    /**
     * Esegue una transizione: blocca la riga, verifica la tabella, applica lo stato di arrivo prima
     * degli effetti (InventoryService::release richiede già lo stato terminale) e registra l'audit.
     *
     * @param  Closure(Reservation): mixed  $effects
     */
    private function transition(Reservation $reservation, ReservationEvent $event, Closure $effects): Reservation
    {
        $this->assertTenant();

        return DB::transaction(function () use ($reservation, $event, $effects): Reservation {
            $locked = Reservation::query()->lockForUpdate()->findOrFail($reservation->id);
            $from = $locked->status;
            $transition = ReservationTransitions::find($from, $event)
                ?? throw IllegalTransition::of('reservation', $from->value, $event->value);

            $locked->status = $transition['to'];
            $effects($locked);

            if ($locked->isDirty()) {
                $locked->version++;
                $locked->save();
            }

            $this->audit->record('reservation.'.$event->value, 'reservation', $locked->id, [
                'transition' => $transition['id'], 'status' => ['from' => $from->value, 'to' => $locked->status->value],
            ]);

            $reservation->setRawAttributes($locked->getAttributes(), true);

            return $locked;
        });
    }

    private function confirmOnline(Reservation $reservation): void
    {
        $reservation->confirmation_kind = ConfirmationKind::ONLINE_PAYMENT;
        $reservation->confirmed_at = CarbonImmutable::now();
        $this->inventory->promoteToFirm($reservation);
        $this->notify($reservation, 'reservation.confirmed');
    }

    /** Pagamento online della prenotazione nello stato atteso (l'ultimo creato). */
    private function onlinePayment(Reservation $reservation, PaymentStatus $status): Payment
    {
        $payment = Payment::query()
            ->where('reservation_id', $reservation->id)
            ->where('purpose', '!=', PaymentPurpose::OFFLINE->value)
            ->where('status', $status->value)
            ->orderByDesc('created_at')->orderByDesc('id')
            ->first();

        if ($payment === null) {
            throw IllegalTransition::of('reservation', $reservation->status->value, 'payment_not_'.strtolower($status->value));
        }

        return $payment;
    }

    private function cancelAuthorizationLater(Reservation $reservation): void
    {
        $payment = Payment::query()
            ->where('reservation_id', $reservation->id)
            ->where('status', PaymentStatus::AUTHORIZED->value)
            ->first();

        if ($payment !== null) {
            $this->afterCommit(fn () => CancelAuthorizationJob::dispatch($reservation->tenant_id, $payment->id));
        }
    }

    private function applyPrice(Reservation $reservation, PriceQuote $price): void
    {
        $reservation->contract_total_cents = $price->totalCents;
        $reservation->deposit_due_cents = $price->depositCents;
        $reservation->balance_due_cents = $price->balanceCents;
    }

    /** Snapshot delle righe di prezzo (I14): non cambiano se cambiano le tariffe. */
    private function savePriceLines(Reservation $reservation, PriceQuote $price): void
    {
        foreach ($price->lines as $position => $line) {
            /** @var PriceLine $line */
            $row = new ReservationPriceLine;
            $row->reservation_id = $reservation->id;
            $row->kind = $line->kind;
            $row->night_date = $line->date === null ? null : CarbonImmutable::parse($line->date);
            $row->quantity = $line->quantity;
            $row->unit_amount_cents = $line->unitAmountCents;
            $row->amount_cents = $line->amountCents;
            $row->position = $position + 1;
            $row->save();
        }
    }

    /**
     * Email in outbox (03: nella stessa transazione). Il payload contiene solo identificativi: i dati
     * dell'ospite si leggono alla consegna e non restano nella coda.
     */
    private function notify(Reservation $reservation, string $type, ?string $discriminator = null): void
    {
        $this->outbox->enqueue($type, $type.':'.$reservation->id.($discriminator === null ? '' : ':'.$discriminator), [
            'reservation_id' => $reservation->id,
        ]);
    }

    private function afterCommit(Closure $callback): void
    {
        DB::afterCommit($callback);
    }

    private function assertTenant(): void
    {
        if (! $this->tenantContext->hasTenant()) {
            throw MissingTenantContext::make();
        }
    }

    /** Codice pubblico di 8 caratteri senza simboli ambigui (02). */
    public static function newCode(): string
    {
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $code = '';

        for ($i = 0; $i < 8; $i++) {
            $code .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }

        return $code;
    }

    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }
}
