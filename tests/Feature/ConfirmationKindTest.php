<?php

use App\Domain\Payments\Enums\PaymentStatus;
use App\Domain\Payments\Gateway\Fake\FakeCustomerAction;
use App\Domain\Reservations\Enums\ConfirmationKind;
use App\Domain\Reservations\Enums\ReservationChannel;
use App\Domain\Reservations\Enums\ReservationStatus;
use App\Domain\Reservations\Exceptions\IllegalTransition;
use App\Domain\Reservations\Models\Reservation;
use Illuminate\Support\Carbon;

/*
 * I11 (02): una prenotazione online diventa CONFIRMED solo dopo incasso riuscito (ONLINE_PAYMENT);
 * una prenotazione MANUAL può essere confermata amministrativamente (ADMINISTRATIVE) anche senza
 * pagamento. Nessun altro percorso porta a CONFIRMED.
 */

beforeEach(function () {
    Carbon::setTestNow('2027-06-01 10:00:00');
    $this->instant = bookableTenant('INSTANT');
    $this->request = bookableTenant('REQUEST');
});
afterEach(fn () => Carbon::setTestNow());

it('INSTANT: CONFIRMED con ONLINE_PAYMENT solo dopo il pagamento riuscito', function () {
    $r = startCheckout($this->instant);
    expect($r->status)->toBe(ReservationStatus::AWAITING_PAYMENT)->and($r->confirmation_kind)->toBeNull()->and($r->confirmed_at)->toBeNull();

    $r = simulateCustomer($this->instant, $r, FakeCustomerAction::PAY);

    expect($r->status)->toBe(ReservationStatus::CONFIRMED)
        ->and($r->confirmation_kind)->toBe(ConfirmationKind::ONLINE_PAYMENT)
        ->and($r->confirmed_at)->not->toBeNull()
        ->and(latestPayment($this->instant, $r->id)->status)->toBe(PaymentStatus::SUCCEEDED);
});

it('INSTANT: un pagamento rifiutato non conferma', function () {
    $r = simulateCustomer($this->instant, startCheckout($this->instant), FakeCustomerAction::DECLINE);

    expect($r->status)->toBe(ReservationStatus::PAYMENT_FAILED)->and($r->confirmation_kind)->toBeNull();
});

it('REQUEST: l\'autorizzazione non conferma; la conferma arriva solo con la capture riuscita', function () {
    $r = simulateCustomer($this->request, startCheckout($this->request), FakeCustomerAction::PAY);
    expect($r->status)->toBe(ReservationStatus::AWAITING_APPROVAL)->and($r->confirmation_kind)->toBeNull()
        ->and(latestPayment($this->request, $r->id)->status)->toBe(PaymentStatus::AUTHORIZED);

    inTenant($this->request['tenant'], fn () => machine()->approve($r));
    $r = reloadReservation($this->request, $r->id);

    expect($r->status)->toBe(ReservationStatus::CONFIRMED)
        ->and($r->confirmation_kind)->toBe(ConfirmationKind::ONLINE_PAYMENT)
        ->and(latestPayment($this->request, $r->id)->status)->toBe(PaymentStatus::SUCCEEDED);
});

it('REQUEST: capture in sospeso o fallita non conferma', function (ReservationStatus $status) {
    $r = reservationIn($this->request, $status);

    expect($r->status)->toBe($status)->and($r->confirmation_kind)->toBeNull()->and($r->confirmed_at)->toBeNull();
})->with([ReservationStatus::CAPTURING, ReservationStatus::CAPTURE_FAILED]);

it('MANUAL: conferma amministrativa senza pagamento (UNPAID)', function () {
    $created = inTenant($this->instant['tenant'], fn () => machine()->createManual(newReservationData($this->instant, channel: ReservationChannel::MANUAL)));
    $r = reloadReservation($this->instant, $created->reservation->id);

    expect($r->status)->toBe(ReservationStatus::CONFIRMED)
        ->and($r->confirmation_kind)->toBe(ConfirmationKind::ADMINISTRATIVE)
        ->and($created->payment)->toBeNull()
        ->and(inTenant($this->instant['tenant'], fn () => $r->payments()->count()))->toBe(0);
});

it('la conferma amministrativa è vietata ai canali online', function (ReservationChannel $channel) {
    expect(fn () => inTenant($this->instant['tenant'], fn () => machine()->createManual(newReservationData($this->instant, channel: $channel))))
        ->toThrow(IllegalTransition::class);

    expect(inTenant($this->instant['tenant'], fn () => Reservation::query()->count()))->toBe(0);
})->with([ReservationChannel::DIRECT_WEB, ReservationChannel::QUOTE]);

it('il checkout online non accetta il canale MANUAL', function () {
    expect(fn () => inTenant($this->instant['tenant'], fn () => machine()->startCheckout(newReservationData($this->instant, channel: ReservationChannel::MANUAL), $this->instant['account'])))
        ->toThrow(IllegalTransition::class);
});

it('ogni prenotazione CONFIRMED ha confirmation_kind coerente con il canale', function () {
    reservationIn($this->instant, ReservationStatus::CONFIRMED, '[2027-07-01,2027-07-04)');
    inTenant($this->instant['tenant'], fn () => machine()->createManual(newReservationData($this->instant, '[2027-07-10,2027-07-14)', channel: ReservationChannel::MANUAL)));

    $confirmed = inTenant($this->instant['tenant'], fn () => Reservation::query()->where('status', ReservationStatus::CONFIRMED->value)->get());

    expect($confirmed)->toHaveCount(2);
    foreach ($confirmed as $r) {
        expect($r->confirmation_kind)->toBe($r->channel === ReservationChannel::MANUAL ? ConfirmationKind::ADMINISTRATIVE : ConfirmationKind::ONLINE_PAYMENT);
    }
});
