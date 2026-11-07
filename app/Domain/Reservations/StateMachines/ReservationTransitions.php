<?php

namespace App\Domain\Reservations\StateMachines;

use App\Domain\Reservations\Enums\ReservationEvent;
use App\Domain\Reservations\Enums\ReservationStatus as S;

/**
 * Tabella delle transizioni T1–T18 di 03 (sezione 1), come dati. ReservationStateMachine la consulta
 * prima di ogni evento: una coppia (stato, evento) assente è una IllegalTransition.
 *
 * Precisazioni (ADR-0011):
 * - T6 non è un evento a sé: il job di rilascio che trova la sessione già completata applica
 *   PAYMENT_SUCCEEDED (T3) o AUTHORIZATION_SUCCEEDED (T4), come farebbe il webhook;
 * - T7 parte dallo stato terminale già raggiunto con il rilascio (EXPIRED dopo T5 o T11,
 *   PAYMENT_FAILED dopo T8 o T16) e non lo cambia.
 */
final class ReservationTransitions
{
    /**
     * @return list<array{id: string, from: S|null, event: ReservationEvent, to: S}>
     */
    public static function all(): array
    {
        return [
            ['id' => 'T1', 'from' => null, 'event' => ReservationEvent::START_CHECKOUT, 'to' => S::AWAITING_PAYMENT],
            ['id' => 'T2', 'from' => null, 'event' => ReservationEvent::CREATE_MANUAL, 'to' => S::CONFIRMED],
            ['id' => 'T3', 'from' => S::AWAITING_PAYMENT, 'event' => ReservationEvent::PAYMENT_SUCCEEDED, 'to' => S::CONFIRMED],
            ['id' => 'T4', 'from' => S::AWAITING_PAYMENT, 'event' => ReservationEvent::AUTHORIZATION_SUCCEEDED, 'to' => S::AWAITING_APPROVAL],
            ['id' => 'T5', 'from' => S::AWAITING_PAYMENT, 'event' => ReservationEvent::CHECKOUT_EXPIRED, 'to' => S::EXPIRED],
            ['id' => 'T7', 'from' => S::EXPIRED, 'event' => ReservationEvent::LATE_PAYMENT, 'to' => S::EXPIRED],
            ['id' => 'T7', 'from' => S::PAYMENT_FAILED, 'event' => ReservationEvent::LATE_PAYMENT, 'to' => S::PAYMENT_FAILED],
            ['id' => 'T8', 'from' => S::AWAITING_PAYMENT, 'event' => ReservationEvent::PAYMENT_FAILED, 'to' => S::PAYMENT_FAILED],
            ['id' => 'T9', 'from' => S::AWAITING_APPROVAL, 'event' => ReservationEvent::APPROVE, 'to' => S::CAPTURING],
            ['id' => 'T10', 'from' => S::AWAITING_APPROVAL, 'event' => ReservationEvent::REJECT, 'to' => S::REJECTED],
            ['id' => 'T11', 'from' => S::AWAITING_APPROVAL, 'event' => ReservationEvent::APPROVAL_EXPIRED, 'to' => S::EXPIRED],
            ['id' => 'T12', 'from' => S::CAPTURING, 'event' => ReservationEvent::CAPTURE_SUCCEEDED, 'to' => S::CONFIRMED],
            ['id' => 'T13', 'from' => S::CAPTURING, 'event' => ReservationEvent::CAPTURE_FAILED, 'to' => S::CAPTURE_FAILED],
            ['id' => 'T14', 'from' => S::CAPTURING, 'event' => ReservationEvent::CAPTURE_UNKNOWN, 'to' => S::CAPTURING],
            ['id' => 'T15', 'from' => S::CAPTURE_FAILED, 'event' => ReservationEvent::SEND_RECOVERY_LINK, 'to' => S::AWAITING_PAYMENT],
            ['id' => 'T16', 'from' => S::CAPTURE_FAILED, 'event' => ReservationEvent::RELEASE_DATES, 'to' => S::PAYMENT_FAILED],
            ['id' => 'T17', 'from' => S::CONFIRMED, 'event' => ReservationEvent::CANCEL, 'to' => S::CANCELLED],
            ['id' => 'T18', 'from' => S::CONFIRMED, 'event' => ReservationEvent::MODIFY, 'to' => S::CONFIRMED],
        ];
    }

    /**
     * Transizione ammessa per lo stato e l'evento, oppure null.
     *
     * @return array{id: string, from: S|null, event: ReservationEvent, to: S}|null
     */
    public static function find(?S $from, ReservationEvent $event): ?array
    {
        foreach (self::all() as $transition) {
            if ($transition['from'] === $from && $transition['event'] === $event) {
                return $transition;
            }
        }

        return null;
    }
}
