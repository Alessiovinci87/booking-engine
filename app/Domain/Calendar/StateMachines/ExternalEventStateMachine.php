<?php

namespace App\Domain\Calendar\StateMachines;

use App\Domain\Calendar\Enums\EchoKind;
use App\Domain\Calendar\Enums\ExternalEventStatus as S;
use App\Domain\Calendar\Import\ParsedEvent;
use App\Domain\Calendar\Models\ExternalCalendar;
use App\Domain\Calendar\Models\ExternalCalendarEvent;
use App\Domain\Reservations\Exceptions\IllegalTransition;
use App\Domain\Tenancy\Audit\AuditLogger;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/**
 * Stati di un evento importato (06 "Rimozione"): ACTIVE → PENDING_REMOVAL alla prima assenza in una
 * risposta valida, → REMOVED alla conferma dopo almeno 10 minuti, → ACTIVE se ricompare. Le date di un
 * evento ACTIVE o PENDING_REMOVAL restano non vendibili (02).
 *
 * L'audit registra date e stati, mai il SUMMARY (09).
 */
final class ExternalEventStateMachine
{
    public const REMOVAL_CONFIRMATION_MINUTES = 10;

    public function __construct(private readonly AuditLogger $audit) {}

    public function create(ExternalCalendar $calendar, ParsedEvent $parsed, EchoKind $echo, ?string $echoOfIcsUid, CarbonImmutable $now): ExternalCalendarEvent
    {
        $event = new ExternalCalendarEvent;
        $event->id = (string) Str::uuid7();
        $event->forceFill([
            'external_calendar_id' => $calendar->id,
            'uid' => $parsed->uid,
            'stay' => $parsed->stay,
            'summary_redacted' => $parsed->summaryRedacted,
            'content_hash' => $parsed->contentHash,
            'first_seen_at' => $now,
            'last_seen_at' => $now,
            'echo_kind' => $echo,
            'echo_of_ics_uid' => $echo === EchoKind::CONFIRMED_ECHO ? $echoOfIcsUid : null,
            'status' => S::ACTIVE,
        ])->save();

        $this->audit->record('external_event.created', 'external_calendar_event', $event->id, [
            'external_calendar_id' => $calendar->id, 'check_in' => $parsed->stay->checkIn->toDateString(),
            'check_out' => $parsed->stay->checkOut->toDateString(), 'echo_kind' => $echo->value,
        ]);

        return $event;
    }

    /**
     * Evento presente nella risposta: aggiorna i dati e, se era in rimozione o rimosso, torna ACTIVE.
     * Restituisce true se le date sono cambiate o l'evento è ricomparso.
     */
    public function seen(ExternalCalendarEvent $event, ParsedEvent $parsed, CarbonImmutable $now): bool
    {
        $previous = $event->stay;
        $datesChanged = ! $previous->equals($parsed->stay);
        $reappeared = $event->status !== S::ACTIVE;

        $event->stay = $parsed->stay;

        if ($datesChanged) {
            // SEQUENCE dell'evento riesportato (`ext-{id}`) nei feed delle altre destinazioni (06).
            $event->ics_sequence++;
        }

        $event->summary_redacted = $parsed->summaryRedacted;
        $event->content_hash = $parsed->contentHash;
        $event->last_seen_at = $now;
        $event->missing_since = null;

        if ($reappeared) {
            $from = $event->status;
            $event->status = S::ACTIVE;
            $event->save();
            $this->audit->record('external_event.status_changed', 'external_calendar_event', $event->id, [
                'from' => $from->value, 'to' => S::ACTIVE->value,
            ]);
        } else {
            $event->save();
        }

        if ($datesChanged) {
            $this->audit->record('external_event.dates_changed', 'external_calendar_event', $event->id, [
                'from' => ['check_in' => $previous->checkIn->toDateString(), 'check_out' => $previous->checkOut->toDateString()],
                'to' => ['check_in' => $parsed->stay->checkIn->toDateString(), 'check_out' => $parsed->stay->checkOut->toDateString()],
            ]);
        }

        return $datesChanged || $reappeared;
    }

    /** Prima assenza in una risposta valida: le date restano bloccate. */
    public function markPendingRemoval(ExternalCalendarEvent $event, CarbonImmutable $now): void
    {
        if ($event->status !== S::ACTIVE) {
            throw IllegalTransition::of('external_event', $event->status->value, S::PENDING_REMOVAL->value);
        }

        $event->missing_since = $now;
        $this->move($event, S::PENDING_REMOVAL);
    }

    /** Seconda assenza ad almeno 10 minuti dalla prima: le date si liberano. */
    public function confirmRemoval(ExternalCalendarEvent $event, CarbonImmutable $now): bool
    {
        if ($event->status !== S::PENDING_REMOVAL || $event->missing_since === null) {
            throw IllegalTransition::of('external_event', $event->status->value, S::REMOVED->value);
        }

        if ($event->missing_since->greaterThan($now->subMinutes(self::REMOVAL_CONFIRMATION_MINUTES))) {
            return false;
        }

        $this->move($event, S::REMOVED);

        return true;
    }

    /** Rimozione manuale del gestore (cambio di URL, 06): solo per eventi non già rimossi. */
    public function removeByManager(ExternalCalendarEvent $event): void
    {
        if ($event->status === S::REMOVED) {
            throw IllegalTransition::of('external_event', $event->status->value, S::REMOVED->value);
        }

        $this->move($event, S::REMOVED, ['by' => 'manager']);
    }

    /**
     * Riclassificazione dell'eco (06 "Classificazione degli echi"): l'eco sospetta riconosciuta dal
     * gestore come prenotazione vera torna NONE.
     */
    public function classify(ExternalCalendarEvent $event, EchoKind $echo, ?string $echoOfIcsUid = null): void
    {
        $from = $event->echo_kind;
        $event->echo_kind = $echo;
        $event->echo_of_ics_uid = $echo === EchoKind::CONFIRMED_ECHO ? $echoOfIcsUid : null;
        $event->save();

        if ($from !== $echo) {
            $this->audit->record('external_event.echo_reclassified', 'external_calendar_event', $event->id, [
                'from' => $from->value, 'to' => $echo->value,
            ]);
        }
    }

    /** @param  array<string, mixed>  $extra */
    private function move(ExternalCalendarEvent $event, S $to, array $extra = []): void
    {
        $from = $event->status;
        $event->status = $to;
        $event->save();

        $this->audit->record('external_event.status_changed', 'external_calendar_event', $event->id, [
            'from' => $from->value, 'to' => $to->value, ...$extra,
        ]);
    }
}
