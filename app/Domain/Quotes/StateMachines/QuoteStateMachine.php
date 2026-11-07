<?php

namespace App\Domain\Quotes\StateMachines;

use App\Domain\Quotes\Enums\QuoteStatus as S;
use App\Domain\Quotes\Models\Quote;
use App\Domain\Reservations\Enums\ReservationStatus;
use App\Domain\Reservations\Exceptions\IllegalTransition;
use App\Domain\Reservations\Models\Reservation;
use App\Domain\Tenancy\Audit\AuditLogger;
use Carbon\CarbonImmutable;

/**
 * Macchina a stati del preventivo (03, sezione 4):
 *
 * | Da | Evento | A |
 * |---|---|---|
 * | — | creazione | DRAFT |
 * | DRAFT | invio / copia link | SENT |
 * | SENT | accept (lock, non scaduto, tentativi < max, date vendibili) | IN_CHECKOUT (+1 tentativo) |
 * | IN_CHECKOUT | prenotazione → AWAITING_APPROVAL, CAPTURING o CONFIRMED | ACCEPTED |
 * | IN_CHECKOUT | prenotazione → EXPIRED / PAYMENT_FAILED, tentativi < max | SENT (EXPIRED se scaduto) |
 * | IN_CHECKOUT | come sopra, tentativi = max | EXHAUSTED |
 * | DRAFT, SENT | expires_at superato | EXPIRED |
 * | DRAFT, SENT, IN_CHECKOUT | revoke (il tentativo attivo prosegue fino al suo esito) | REVOKED |
 *
 * Il chiamante tiene bloccata la riga del preventivo (FOR UPDATE), tranne reservationChanged che la
 * blocca da sé. Nell'audit mai nome o email dell'ospite (09).
 */
final class QuoteStateMachine
{
    public function __construct(private readonly AuditLogger $audit) {}

    /** @param  array<string, mixed>  $attributes */
    public function create(array $attributes): Quote
    {
        $quote = new Quote;
        $quote->forceFill($attributes);
        $quote->status = S::DRAFT;
        $quote->save();

        $this->audit->record('quote.created', 'quote', $quote->id, [
            'unit_id' => $quote->unit_id, 'stay' => (string) $quote->stay, 'final_total_cents' => $quote->final_total_cents,
            'discount_cents' => $quote->discount_cents, 'expires_at' => $quote->expires_at->toIso8601ZuluString(),
        ]);

        return $quote;
    }

    /** Invio o copia del link: DRAFT → SENT; un nuovo invio di un SENT non cambia stato. */
    public function send(Quote $quote, string $channel): void
    {
        if ($quote->status === S::SENT) {
            $this->audit->record('quote.resent', 'quote', $quote->id, ['channel' => $channel]);

            return;
        }

        if ($quote->status !== S::DRAFT) {
            throw IllegalTransition::of('quote', $quote->status->value, 'send');
        }

        $this->move($quote, S::SENT, ['channel' => $channel]);
    }

    public function startCheckout(Quote $quote, Reservation $reservation): void
    {
        if ($quote->status !== S::SENT || $quote->checkout_attempts >= $quote->max_checkout_attempts) {
            throw IllegalTransition::of('quote', $quote->status->value, 'accept');
        }

        $quote->active_reservation_id = $reservation->id;
        $quote->checkout_attempts++;
        $this->move($quote, S::IN_CHECKOUT, ['reservation_id' => $reservation->id, 'checkout_attempts' => $quote->checkout_attempts]);
    }

    /**
     * Esito della prenotazione del tentativo attivo (chiamato dalla ReservationStateMachine nella stessa
     * transazione). Ignora le prenotazioni che non sono il tentativo attivo del preventivo.
     */
    public function reservationChanged(Reservation $reservation): void
    {
        if ($reservation->quote_id === null) {
            return;
        }

        $quote = Quote::query()->lockForUpdate()->find($reservation->quote_id);

        if ($quote === null || $quote->active_reservation_id !== $reservation->id) {
            return;
        }

        $accepted = in_array($reservation->status, [ReservationStatus::AWAITING_APPROVAL, ReservationStatus::CAPTURING, ReservationStatus::CONFIRMED], true);
        $failed = in_array($reservation->status, [ReservationStatus::EXPIRED, ReservationStatus::PAYMENT_FAILED], true);

        if (! $accepted && ! $failed) {
            return;
        }

        if ($quote->status === S::REVOKED) {
            // Ritirato durante il checkout: il tentativo ha avuto il suo esito, il preventivo resta ritirato.
            if ($failed) {
                $quote->active_reservation_id = null;
                $quote->version++;
                $quote->save();
            }

            $this->audit->record('quote.attempt_finished', 'quote', $quote->id, ['reservation_id' => $reservation->id, 'reservation_status' => $reservation->status->value]);

            return;
        }

        if ($quote->status !== S::IN_CHECKOUT) {
            return;
        }

        if ($accepted) {
            $this->move($quote, S::ACCEPTED, ['reservation_id' => $reservation->id]);

            return;
        }

        $quote->active_reservation_id = null;
        $to = match (true) {
            $quote->checkout_attempts >= $quote->max_checkout_attempts => S::EXHAUSTED,
            CarbonImmutable::now()->greaterThanOrEqualTo($quote->expires_at) => S::EXPIRED,
            default => S::SENT,
        };

        $this->move($quote, $to, ['reservation_id' => $reservation->id, 'reservation_status' => $reservation->status->value]);
    }

    public function expire(Quote $quote): void
    {
        if (! in_array($quote->status, [S::DRAFT, S::SENT], true)) {
            throw IllegalTransition::of('quote', $quote->status->value, 'expire');
        }

        $this->move($quote, S::EXPIRED, []);
    }

    public function revoke(Quote $quote, ?string $userId): void
    {
        if (! in_array($quote->status, [S::DRAFT, S::SENT, S::IN_CHECKOUT], true)) {
            throw IllegalTransition::of('quote', $quote->status->value, 'revoke');
        }

        $this->move($quote, S::REVOKED, ['user_id' => $userId, 'active_reservation_id' => $quote->active_reservation_id]);
    }

    /** @param  array<string, mixed>  $extra */
    private function move(Quote $quote, S $to, array $extra): void
    {
        $from = $quote->status;
        $quote->status = $to;
        $quote->version++;
        $quote->save();

        $this->audit->record('quote.status_changed', 'quote', $quote->id, ['from' => $from->value, 'to' => $to->value, ...$extra]);
    }
}
