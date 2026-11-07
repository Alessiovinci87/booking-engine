<?php

namespace App\Domain\Reservations\Outbox;

use App\Domain\Communications\TransactionalEmail;
use App\Domain\Communications\TransactionalMailer;
use App\Domain\Payments\Models\Payment;
use App\Domain\Payments\Models\Refund;
use App\Domain\Reservations\Enums\ReservationStatus;
use App\Domain\Reservations\Models\Guest;
use App\Domain\Reservations\Models\Reservation;
use App\Domain\Tenancy\Models\OutboxMessage;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\Outbox\OutboxHandler;
use Illuminate\Support\Number;

/**
 * Consegna delle email delle transizioni (03). Il payload contiene solo identificativi: destinatario
 * e testi si ricavano qui, alla consegna, nella lingua dell'ospite (o in italiano per il gestore).
 * Un ospite anonimizzato non riceve più nulla (09).
 */
final class SendReservationEmail implements OutboxHandler
{
    public function __construct(private readonly TransactionalMailer $mailer) {}

    /** Tipi destinati al gestore (email di contatto della struttura). */
    private const MANAGER_TYPES = [
        'reservation.approval_requested', 'reservation.approval_expired_manager', 'reservation.confirmed_manager', 'reservation.capture_failed',
        'reservation.capture_failed_reminder', 'reservation.dispute_opened', 'reservation.refund_failed', 'reservation.refund_reversed',
    ];

    public function handle(OutboxMessage $message): void
    {
        $payload = $message->payload;
        $reservation = Reservation::query()->with('unit.property')->find($payload['reservation_id'] ?? null);

        if ($reservation === null) {
            return;
        }

        // Promemoria della capture fallita: superato se nel frattempo il gestore ha deciso.
        if ($message->type === 'reservation.capture_failed_reminder' && $reservation->status !== ReservationStatus::CAPTURE_FAILED) {
            return;
        }

        $guest = Guest::query()->findOrFail($reservation->guest_id);
        $property = $reservation->unit->property;
        $toManager = in_array($message->type, self::MANAGER_TYPES, true);
        $recipient = $toManager ? $property->contact_email : ($guest->anonymized_at === null ? $guest->email : null);

        if ($recipient === null || $recipient === '') {
            return;
        }

        $locale = $toManager ? 'it' : $reservation->guest_locale->value;
        $key = 'emails.'.str_replace('reservation.', '', $message->type);
        $replace = [
            'code' => $reservation->code,
            'property' => $property->name,
            'unit' => $reservation->unit->name,
            'check_in' => $reservation->stay->checkIn->toDateString(),
            'check_out' => $reservation->stay->checkOut->toDateString(),
            'amount' => $this->amount($message, $reservation, $locale),
            // Solo nelle email al gestore, titolare del trattamento (09).
            'guest' => $toManager ? trim($guest->first_name.' '.$guest->last_name) : '',
        ];

        /** @var list<string> $lines */
        $lines = array_values((array) __($key.'.lines', $replace, $locale));

        $this->mailer->send(new TransactionalEmail(
            to: $recipient,
            locale: $locale,
            subject: (string) __($key.'.subject', $replace, $locale),
            greeting: (string) __('emails.greeting', [], $locale),
            lines: $lines,
            link: $this->link($reservation, $toManager),
            signature: (string) __('emails.signature', ['property' => $property->name], $locale),
            category: $message->type,
            idempotencyKey: 'outbox:'.$message->id,
            // L'ospite risponde alla struttura, non all'indirizzo di invio della piattaforma.
            replyTo: $toManager ? null : $property->contact_email,
        ));
    }

    private function amount(OutboxMessage $message, Reservation $reservation, string $locale): string
    {
        $cents = match ($message->type) {
            'reservation.refund_completed', 'reservation.refund_failed', 'reservation.refund_reversed' => (int) Refund::query()->whereKey($message->payload['refund_id'] ?? null)->value('amount_cents'),
            'reservation.cancelled' => (int) Refund::query()
                ->whereIn('payment_id', Payment::query()->select('id')->where('reservation_id', $reservation->id))
                ->sum('amount_cents'),
            default => $reservation->contract_total_cents,
        };

        return Number::currency($cents / 100, in: $reservation->currency, locale: $locale);
    }

    private function link(Reservation $reservation, bool $toManager): string
    {
        $base = rtrim((string) config('app.url'), '/');

        if ($toManager) {
            return $base.'/app/prenotazioni/'.$reservation->id;
        }

        // Anche il link di recupero porta alla pagina della prenotazione: l'url della sessione di
        // Stripe non è salvato (02 non ha il campo); il pagamento dal link arriva con M4 (4.6).
        $tenant = Tenant::query()->whereKey($reservation->tenant_id)->value('slug');

        return $base.'/p/'.$tenant.'/'.$reservation->unit->property->slug.'/prenotazione/'.$reservation->code;
    }
}
