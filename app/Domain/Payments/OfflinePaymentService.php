<?php

namespace App\Domain\Payments;

use App\Domain\Payments\Enums\OfflineMethod;
use App\Domain\Payments\Models\Payment;
use App\Domain\Payments\StateMachines\PaymentStateMachine;
use App\Domain\Reservations\Enums\ReservationStatus;
use App\Domain\Reservations\Exceptions\IllegalTransition;
use App\Domain\Reservations\Models\Reservation;
use App\Domain\Reservations\ReservationEconomics;
use App\Domain\Tenancy\Audit\AuditLogger;
use Illuminate\Validation\ValidationException;

/**
 * Pagamento registrato fuori piattaforma (05 flusso D, AC17): bonifico, contanti, POS o altro su una
 * prenotazione CONFIRMED. Non cambia lo stato della prenotazione, solo quello economico (derivato).
 * L'importo non può superare quanto resta da incassare rispetto al totale contrattuale.
 * Va chiamato dentro il contesto tenant.
 */
final class OfflinePaymentService
{
    public function __construct(
        private readonly PaymentStateMachine $payments,
        private readonly AuditLogger $audit,
    ) {}

    public function record(Reservation $reservation, int $amountCents, OfflineMethod $method): Payment
    {
        $locked = Reservation::query()->lockForUpdate()->findOrFail($reservation->id);

        if ($locked->status !== ReservationStatus::CONFIRMED) {
            throw IllegalTransition::of('reservation', $locked->status->value, 'record_offline_payment');
        }

        $outstanding = ReservationEconomics::of($locked)->outstandingCents();

        if ($amountCents < 1 || $amountCents > $outstanding) {
            throw ValidationException::withMessages([
                'amount_cents' => __('reservations.validation.offline_amount', ['max' => $outstanding]),
            ]);
        }

        $payment = $this->payments->createOffline($locked, $amountCents, $method);

        $this->audit->record('payment.offline_recorded', 'payment', $payment->id, [
            'reservation_id' => $locked->id, 'amount_cents' => $amountCents, 'method' => $method->value,
        ]);

        return $payment;
    }
}
