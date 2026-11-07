<?php

use App\Domain\Inventory\Enums\OccupancyPhase;
use App\Domain\Inventory\Enums\OccupancyTempReason;
use App\Domain\Inventory\InventoryService;
use App\Domain\Inventory\Models\InventoryOccupancy;
use App\Domain\Payments\CheckoutService;
use App\Domain\Payments\Enums\OfflineMethod;
use App\Domain\Payments\Enums\PaymentStatus;
use App\Domain\Payments\Enums\RefundStatus;
use App\Domain\Payments\Gateway\Fake\FakeCustomerAction;
use App\Domain\Payments\Gateway\Fake\FakeOperation;
use App\Domain\Payments\Models\Refund;
use App\Domain\Payments\SimulatedPaymentEvents;
use App\Domain\Pricing\Calculator\PriceRequest;
use App\Domain\Pricing\PriceQuoteService;
use App\Domain\Reservations\Enums\ConfirmationKind;
use App\Domain\Reservations\Enums\ReservationChannel;
use App\Domain\Reservations\Enums\ReservationEvent;
use App\Domain\Reservations\Enums\ReservationStatus as S;
use App\Domain\Reservations\Exceptions\IllegalTransition;
use App\Domain\Reservations\Models\Reservation;
use App\Domain\Reservations\Models\ReservationChange;
use App\Domain\Reservations\StateMachines\ReservationTransitions;
use App\Domain\Reservations\StayRange;
use App\Domain\Tenancy\Exceptions\BusinessRuleViolation;
use App\Domain\Tenancy\Models\AuditLog;
use App\Domain\Tenancy\Models\OutboxMessage;
use Illuminate\Support\Carbon;

/*
 * 3.4: tabella delle transizioni T1–T18 di 03, un test per riga, più tutte le coppie (stato, evento)
 * non ammesse. Ogni transizione lascia stato e occupazione coerenti (I15).
 */

beforeEach(function () {
    Carbon::setTestNow('2027-06-01 10:00:00');
    $this->instant = bookableTenant('INSTANT');
    $this->request = bookableTenant('REQUEST');
});

afterEach(fn () => Carbon::setTestNow());

function occupancyOf(array $fx, Reservation $r): ?InventoryOccupancy
{
    return inTenant($fx['tenant'], fn () => app(InventoryService::class)->findFor($r));
}

function outboxTypes(array $fx, Reservation $r): array
{
    return inTenant($fx['tenant'], fn () => OutboxMessage::query()->where('payload->reservation_id', $r->id)->pluck('type')->sort()->values()->all());
}

afterEach(function () {
    foreach (['instant', 'request'] as $fx) {
        if (isset($this->{$fx})) {
            expect(occupancyViolations($this->{$fx}['tenant']))->toBe([]);
        }
    }
});

it('T1: avvio del checkout con occupazione CHECKOUT allineata alla sessione e Payment PENDING', function () {
    $r = startCheckout($this->instant);
    $occupancy = occupancyOf($this->instant, $r);
    $payment = latestPayment($this->instant, $r->id);

    expect($r->status)->toBe(S::AWAITING_PAYMENT)
        ->and($occupancy->phase)->toBe(OccupancyPhase::TEMPORARY)
        ->and($occupancy->temp_reason)->toBe(OccupancyTempReason::CHECKOUT)
        ->and($payment->status)->toBe(PaymentStatus::PENDING)
        ->and($payment->checkout_session_id)->toStartWith('cs_fake_')
        ->and($payment->checkout_expires_at->toIso8601String())->toBe('2027-06-01T10:32:00+00:00')
        ->and($occupancy->expires_at->toIso8601String())->toBe('2027-06-01T10:37:00+00:00')
        ->and($payment->amount_cents)->toBe($r->deposit_due_cents);
});

it('T2: prenotazione manuale CONFIRMED amministrativa, con pagamento offline facoltativo', function () {
    $created = inTenant($this->instant['tenant'], fn () => machine()->createManual(
        newReservationData($this->instant, channel: ReservationChannel::MANUAL), 10000, OfflineMethod::BANK_TRANSFER,
    ));
    $r = $created->reservation;

    expect($r->status)->toBe(S::CONFIRMED)
        ->and($r->confirmation_kind)->toBe(ConfirmationKind::ADMINISTRATIVE)
        ->and(occupancyOf($this->instant, $r)->phase)->toBe(OccupancyPhase::FIRM)
        ->and($created->payment?->status)->toBe(PaymentStatus::SUCCEEDED);
});

it('T3: pagamento riuscito di una prenotazione INSTANT → CONFIRMED, occupazione FIRM, email', function () {
    $r = reservationIn($this->instant, S::CONFIRMED);

    expect($r->status)->toBe(S::CONFIRMED)
        ->and($r->confirmation_kind)->toBe(ConfirmationKind::ONLINE_PAYMENT)
        ->and(occupancyOf($this->instant, $r)->phase)->toBe(OccupancyPhase::FIRM)
        ->and(latestPayment($this->instant, $r->id)->status)->toBe(PaymentStatus::SUCCEEDED)
        ->and(outboxTypes($this->instant, $r))->toBe(['reservation.confirmed']);
});

it('T4: autorizzazione di una richiesta → AWAITING_APPROVAL con approval_deadline', function () {
    $r = reservationIn($this->request, S::AWAITING_APPROVAL);
    $occupancy = occupancyOf($this->request, $r);

    expect($r->status)->toBe(S::AWAITING_APPROVAL)
        ->and($r->approval_deadline->toIso8601String())->toBe('2027-06-01T22:00:00+00:00')
        ->and($occupancy->temp_reason)->toBe(OccupancyTempReason::AWAITING_APPROVAL)
        ->and($occupancy->expires_at->equalTo($r->approval_deadline))->toBeTrue()
        ->and(latestPayment($this->request, $r->id)->status)->toBe(PaymentStatus::AUTHORIZED)
        ->and(outboxTypes($this->request, $r))->toBe(['reservation.approval_requested', 'reservation.awaiting_approval']);
});

it('T5: il job chiude la sessione scaduta e rilascia → EXPIRED', function () {
    $r = reservationIn($this->instant, S::EXPIRED);

    expect($r->status)->toBe(S::EXPIRED)
        ->and(latestPayment($this->instant, $r->id)->status)->toBe(PaymentStatus::EXPIRED)
        ->and(fakeGateway()->calls(FakeOperation::EXPIRE)[0]['idempotency_key'])->toBe('expire:'.latestPayment($this->instant, $r->id)->id);
});

it('T6: il job trova la sessione già pagata → nessun rilascio, CONFIRMED', function () {
    $r = startCheckout($this->instant);
    fakeGateway()->simulateCustomer(latestPayment($this->instant, $r->id)->checkout_session_id, FakeCustomerAction::PAY);
    Carbon::setTestNow('2027-06-01 10:40:00');

    expect(app(CheckoutService::class)->releaseExpired($this->instant['tenant'], $r->id))->toBe('completed')
        ->and(reloadReservation($this->instant, $r->id)->status)->toBe(S::CONFIRMED);
});

it('T7: pagamento arrivato dopo il rilascio → resta EXPIRED, rimborso automatico ed email di scuse (I4)', function () {
    $r = reservationIn($this->instant, S::EXPIRED);
    $payment = latestPayment($this->instant, $r->id);

    $state = fakeGateway()->completeLate((string) $payment->checkout_session_id);
    app(SimulatedPaymentEvents::class)->dispatch($this->instant['account'], $state);

    $refund = inTenant($this->instant['tenant'], fn () => Refund::query()->where('payment_id', $payment->id)->first());
    expect(reloadReservation($this->instant, $r->id)->status)->toBe(S::EXPIRED)
        ->and($refund?->amount_cents)->toBe($payment->amount_cents)
        ->and($refund?->status)->toBe(RefundStatus::SUCCEEDED)
        ->and(outboxTypes($this->instant, $r))->toContain('reservation.late_payment');

    $audit = inTenant($this->instant['tenant'], fn () => AuditLog::query()->where('action', 'reservation.late_payment')->where('subject_id', $r->id)->exists());
    expect($audit)->toBeTrue();
});

it('T8: pagamento rifiutato → sessione chiusa, poi PAYMENT_FAILED', function () {
    $r = reservationIn($this->instant, S::PAYMENT_FAILED);

    expect($r->status)->toBe(S::PAYMENT_FAILED)
        ->and(latestPayment($this->instant, $r->id)->status)->toBe(PaymentStatus::FAILED)
        ->and(fakeGateway()->calls(FakeOperation::EXPIRE))->toHaveCount(1);
});

it('T9 e T12: approvazione → CAPTURING, capture riuscita → CONFIRMED', function () {
    $r = reservationIn($this->request, S::AWAITING_APPROVAL);
    inTenant($this->request['tenant'], fn () => machine()->approve($r));
    $r = reloadReservation($this->request, $r->id);

    expect($r->status)->toBe(S::CONFIRMED)
        ->and($r->confirmation_kind)->toBe(ConfirmationKind::ONLINE_PAYMENT)
        ->and(latestPayment($this->request, $r->id)->status)->toBe(PaymentStatus::SUCCEEDED)
        ->and(fakeGateway()->calls(FakeOperation::CAPTURE)[0]['idempotency_key'])->toBe('capture:'.latestPayment($this->request, $r->id)->id);

    $audits = inTenant($this->request['tenant'], fn () => AuditLog::query()->where('subject_id', $r->id)->where('action', 'like', 'reservation.%')->pluck('changes')->map(fn ($c) => $c['transition'] ?? null)->all());
    expect($audits)->toContain('T9')->toContain('T12');
});

it('T9: rifiuta l\'approvazione con conflitti aperti (409 CONFLICT_OPEN) o dopo la scadenza', function () {
    $r = reservationIn($this->request, S::AWAITING_APPROVAL);
    createConflictRow($this->request['tenant'], $this->request['unit'], '[2027-07-11,2027-07-12)');

    expect(fn () => inTenant($this->request['tenant'], fn () => machine()->approve($r)))
        ->toThrow(fn (BusinessRuleViolation $e) => expect($e->errorCode)->toBe('CONFLICT_OPEN'));

    $other = reservationIn($this->request, S::AWAITING_APPROVAL, '[2027-07-20,2027-07-24)');
    Carbon::setTestNow('2027-06-01 22:00:00');
    expect(fn () => inTenant($this->request['tenant'], fn () => machine()->approve($other)))->toThrow(IllegalTransition::class);
});

it('T10: rifiuto → REJECTED, date libere, autorizzazione annullata', function () {
    $r = reservationIn($this->request, S::REJECTED);

    expect($r->status)->toBe(S::REJECTED)
        ->and(latestPayment($this->request, $r->id)->status)->toBe(PaymentStatus::AUTHORIZATION_CANCELLED)
        ->and(fakeGateway()->calls(FakeOperation::CANCEL)[0]['idempotency_key'])->toBe('cancel:'.latestPayment($this->request, $r->id)->id);
});

it('T11: approval_deadline superata → EXPIRED, autorizzazione annullata, email a ospite e gestore', function () {
    $r = reservationIn($this->request, S::AWAITING_APPROVAL);
    expect(fn () => inTenant($this->request['tenant'], fn () => machine()->approvalExpired($r)))->toThrow(IllegalTransition::class);

    Carbon::setTestNow('2027-06-01 22:00:01');
    inTenant($this->request['tenant'], fn () => machine()->approvalExpired($r));

    expect(reloadReservation($this->request, $r->id)->status)->toBe(S::EXPIRED)
        ->and(latestPayment($this->request, $r->id)->status)->toBe(PaymentStatus::AUTHORIZATION_CANCELLED)
        ->and(outboxTypes($this->request, $r))->toContain('reservation.approval_expired')->toContain('reservation.approval_expired_manager');
});

it('T13: capture rifiutata → CAPTURE_FAILED, date ancora occupate per 24 h', function () {
    $r = reservationIn($this->request, S::CAPTURE_FAILED);
    $occupancy = occupancyOf($this->request, $r);

    expect($r->status)->toBe(S::CAPTURE_FAILED)
        ->and($r->decision_deadline->toIso8601String())->toBe('2027-06-02T10:00:00+00:00')
        ->and($occupancy->temp_reason)->toBe(OccupancyTempReason::CAPTURE_FAILED)
        ->and(latestPayment($this->request, $r->id)->status)->toBe(PaymentStatus::CAPTURE_FAILED)
        ->and(outboxTypes($this->request, $r))->toContain('reservation.capture_failed');
});

it('T14: esito della capture sconosciuto → resta CAPTURING, nessun rilascio', function () {
    $r = reservationIn($this->request, S::CAPTURING);

    expect($r->status)->toBe(S::CAPTURING)
        ->and(occupancyOf($this->request, $r)->temp_reason)->toBe(OccupancyTempReason::CAPTURING)
        ->and(latestPayment($this->request, $r->id)->status)->toBe(PaymentStatus::CAPTURE_PENDING);
});

it('T15: link di recupero → AWAITING_PAYMENT con Payment RECOVERY; pagato → CONFIRMED (T3)', function () {
    $r = reservationIn($this->request, S::CAPTURE_FAILED);
    app(CheckoutService::class)->sendRecoveryLink($this->request['tenant'], $r->id, 24);
    $r = reloadReservation($this->request, $r->id);
    $payment = latestPayment($this->request, $r->id);

    expect($r->status)->toBe(S::AWAITING_PAYMENT)
        ->and($payment->purpose->value)->toBe('RECOVERY')
        ->and($payment->capture_method->value)->toBe('AUTOMATIC')
        ->and($payment->checkout_expires_at->equalTo($r->recovery_deadline))->toBeTrue()
        ->and(occupancyOf($this->request, $r)->temp_reason)->toBe(OccupancyTempReason::PAYMENT_RECOVERY)
        ->and(occupancyOf($this->request, $r)->expires_at->equalTo($r->recovery_deadline->addMinutes(5)))->toBeTrue();

    expect(simulateCustomer($this->request, $r, FakeCustomerAction::PAY)->status)->toBe(S::CONFIRMED);
    expect(fn () => app(CheckoutService::class)->sendRecoveryLink($this->request['tenant'], $r->id, 24))->toThrow(IllegalTransition::class);
});

it('T16: rilascio delle date dopo capture fallita → PAYMENT_FAILED', function () {
    $r = reservationIn($this->request, S::CAPTURE_FAILED);
    inTenant($this->request['tenant'], fn () => machine()->releaseDates($r));

    expect(reloadReservation($this->request, $r->id)->status)->toBe(S::PAYMENT_FAILED)
        ->and(outboxTypes($this->request, $r))->toContain('reservation.dates_released');
});

it('T17: cancellazione → CANCELLED, date libere, rimborso avviato e completato sul gateway finto', function () {
    $r = reservationIn($this->instant, S::CANCELLED);
    $payment = latestPayment($this->instant, $r->id);
    $refund = inTenant($this->instant['tenant'], fn () => Refund::query()->where('payment_id', $payment->id)->firstOrFail());

    expect($r->status)->toBe(S::CANCELLED)
        ->and($r->cancelled_at)->not->toBeNull()
        ->and($refund->status)->toBe(RefundStatus::SUCCEEDED)
        ->and($refund->amount_cents)->toBe($payment->captured_cents)
        ->and($payment->status)->toBe(PaymentStatus::REFUNDED)
        ->and(outboxTypes($this->instant, $r))->toContain('reservation.cancelled')->toContain('reservation.refund_completed');
});

it('T18: modifica di date e ospiti sulla stessa occupazione, con ReservationChange', function () {
    $r = reservationIn($this->instant, S::CONFIRMED);
    $before = occupancyOf($this->instant, $r);
    $stay = StayRange::of('2027-07-11', '2027-07-16');

    inTenant($this->instant['tenant'], function () use ($r, $stay) {
        $price = app(PriceQuoteService::class)->quote($r->unit, new PriceRequest($stay, 3, 0, 0, false));
        machine()->modify($r, $stay, 3, 0, $price, 'Chiesto al telefono', null);
    });

    $after = occupancyOf($this->instant, $r);
    $change = inTenant($this->instant['tenant'], fn () => ReservationChange::query()->where('reservation_id', $r->id)->firstOrFail());
    $reloaded = reloadReservation($this->instant, $r->id);

    expect($after->id)->toBe($before->id)
        ->and($after->ics_uid)->toBe($before->ics_uid)
        ->and((string) $after->stay)->toBe('[2027-07-11,2027-07-16)')
        ->and($reloaded->status)->toBe(S::CONFIRMED)
        ->and($reloaded->adults)->toBe(3)
        ->and($reloaded->contract_total_cents)->toBe(5 * 12000 + 6000)
        ->and($change->previous_contract_total_cents)->toBe(4 * 12000 + 6000)
        ->and((string) $change->previous_stay)->toBe('[2027-07-10,2027-07-14)');
});

it('la tabella copre T1–T18 (T6 tramite T3/T4)', function () {
    $ids = array_unique(array_column(ReservationTransitions::all(), 'id'));

    expect(array_values($ids))->toBe(['T1', 'T2', 'T3', 'T4', 'T5', 'T7', 'T8', 'T9', 'T10', 'T11', 'T12', 'T13', 'T14', 'T15', 'T16', 'T17', 'T18']);
});

it('rifiuta ogni coppia (stato, evento) non prevista dalla tabella, senza effetti', function () {
    $reservations = [];

    foreach (S::cases() as $status) {
        $fx = in_array($status, [S::AWAITING_APPROVAL, S::CAPTURING, S::CAPTURE_FAILED, S::REJECTED], true) ? $this->request : $this->instant;
        $start = Carbon::parse('2027-09-01')->addDays(count($reservations) * 5);
        $reservations[$status->value] = [$fx, reservationIn($fx, $status, '['.$start->toDateString().','.$start->addDays(3)->toDateString().')')];
        Carbon::setTestNow('2027-06-01 10:00:00');
    }

    $checked = 0;

    foreach ($reservations as [$fx, $reservation]) {
        foreach (ReservationEvent::cases() as $event) {
            if ($event->creates() || ReservationTransitions::find($reservation->status, $event) !== null) {
                continue;
            }

            $before = reloadReservation($fx, $reservation->id);

            expect(fn () => inTenant($fx['tenant'], fn () => fireEvent($fx, $before, $event)))
                ->toThrow(IllegalTransition::class);

            $after = reloadReservation($fx, $reservation->id);
            expect($after->status)->toBe($before->status)->and($after->version)->toBe($before->version);
            $checked++;
        }
    }

    // 9 stati × 15 eventi non creativi − 16 coppie ammesse (righe della tabella senza T1 e T2).
    expect($checked)->toBe(9 * 15 - 16);
});
