<?php

namespace App\Domain\Calendar\Import;

use App\Domain\Reservations\StayRange;
use Carbon\CarbonImmutable;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Sabre\VObject\Component;
use Sabre\VObject\Component\VCalendar;
use Sabre\VObject\Property;
use Sabre\VObject\Property\ICalendar\DateTime as DateTimeProperty;
use Sabre\VObject\Property\ICalendar\Duration;
use Sabre\VObject\Reader;
use Throwable;

/**
 * Lettura di un calendario iCal importato (06 "Elaborazione"; 5.2), con sabre/vobject:
 *
 * - ogni VEVENT diventa un intervallo di notti intere [check_in, check_out) nel fuso della struttura:
 *   le date (`VALUE=DATE`) si usano così come sono; un orario si porta nel fuso della struttura,
 *   l'inizio si arrotonda al giorno e una fine dopo la mezzanotte occupa anche quella notte;
 * - eventi ricorrenti (RRULE, RDATE, RECURRENCE-ID) ignorati e contati: le OTA non li usano per le
 *   prenotazioni (ADR-0013);
 * - eventi annullati (`STATUS:CANCELLED`) trattati come assenti;
 * - eventi già conclusi ignorati;
 * - più di 5000 eventi → calendario rifiutato;
 * - UID mancante → hash di DTSTART|DTEND|SUMMARY;
 * - del SUMMARY si conservano solo le etichette neutre note delle OTA: qualsiasi altro testo può
 *   contenere un nome e non si salva (09).
 */
final class IcsParser
{
    public const MAX_EVENTS = 5000;

    /** Etichette senza dati personali dei feed di export noti (ipotesi da verificare in fase 0). */
    private const NEUTRAL_SUMMARIES = [
        'not available', 'closed - not available', 'airbnb (not available)', 'reserved', 'blocked',
        'non disponibile', 'unavailable', 'booked', 'occupato',
    ];

    /**
     * @throws IcsParseFailed
     */
    public function parse(string $body, string $timezone, ?CarbonImmutable $today = null): ParsedCalendar
    {
        $zone = new DateTimeZone($timezone);
        // Le date di StayRange sono mezzanotti UTC: il "giorno di oggi" della struttura si porta lì.
        $today = CarbonImmutable::parse(($today ?? CarbonImmutable::now($zone))->toDateString(), 'UTC');

        if (! str_contains(substr(ltrim($body, "\xEF\xBB\xBF \r\n\t"), 0, 64), 'BEGIN:VCALENDAR')) {
            throw new IcsParseFailed(IcsParseFailed::INVALID_ICS);
        }

        try {
            $calendar = Reader::read($body, Reader::OPTION_FORGIVING);
        } catch (Throwable) {
            throw new IcsParseFailed(IcsParseFailed::INVALID_ICS);
        }

        if (! $calendar instanceof VCalendar) {
            throw new IcsParseFailed(IcsParseFailed::INVALID_ICS);
        }

        $vevents = $calendar->select('VEVENT');

        if (count($vevents) > self::MAX_EVENTS) {
            throw new IcsParseFailed(IcsParseFailed::TOO_MANY_EVENTS);
        }

        $events = [];
        $recurring = 0;
        $invalid = 0;

        foreach ($vevents as $vevent) {
            if (! $vevent instanceof Component) {
                continue;
            }

            if (isset($vevent->RRULE) || isset($vevent->RDATE) || isset($vevent->{'RECURRENCE-ID'})) {
                $recurring++;

                continue;
            }

            if (strtoupper(self::text($vevent, 'STATUS') ?? '') === 'CANCELLED') {
                continue;
            }

            $stay = $this->stay($vevent, $zone);

            if ($stay === null) {
                $invalid++;

                continue;
            }

            if ($stay->checkOut->lessThanOrEqualTo($today)) {
                continue;
            }

            $summary = self::text($vevent, 'SUMMARY');
            $uid = self::text($vevent, 'UID');
            $uid = $uid === null || $uid === ''
                ? 'hash-'.hash('sha256', $stay->checkIn->toDateString().'|'.$stay->checkOut->toDateString().'|'.($summary ?? ''))
                : mb_substr($uid, 0, 500);

            $events[$uid] = new ParsedEvent(
                $uid,
                $stay,
                self::neutralSummary($summary),
                hash('sha256', $uid.'|'.$stay->toPostgres().'|'.($summary ?? '')),
            );
        }

        return new ParsedCalendar(array_values($events), $recurring, $invalid);
    }

    private function stay(Component $vevent, DateTimeZone $zone): ?StayRange
    {
        $start = $vevent->DTSTART ?? null;

        if (! $start instanceof DateTimeProperty) {
            return null;
        }

        try {
            $startAt = $start->getDateTime($zone)->setTimezone($zone);
            $allDay = ! $start->hasTime();
            $end = $vevent->DTEND ?? null;

            if ($end instanceof DateTimeProperty) {
                $endAt = $end->getDateTime($zone)->setTimezone($zone);
            } elseif (($duration = $vevent->DURATION ?? null) instanceof Duration) {
                $endAt = $startAt->add($duration->getDateInterval());
            } else {
                $endAt = $startAt->modify('+1 day');
            }
        } catch (Throwable) {
            return null;
        }

        $checkIn = self::day($startAt);
        $checkOut = self::day($endAt);

        // Una fine con orario dopo la mezzanotte occupa anche quella notte.
        if (! $allDay && $endAt->format('H:i:s') !== '00:00:00') {
            $checkOut = $checkOut->addDay();
        }

        if ($checkOut->lessThanOrEqualTo($checkIn)) {
            $checkOut = $checkIn->addDay();
        }

        return StayRange::of($checkIn, $checkOut);
    }

    private static function day(DateTimeInterface $at): CarbonImmutable
    {
        return CarbonImmutable::parse(DateTimeImmutable::createFromInterface($at)->format('Y-m-d'), 'UTC');
    }

    private static function text(Component $vevent, string $name): ?string
    {
        $property = $vevent->{$name} ?? null;

        return $property instanceof Property ? trim((string) $property->getValue()) : null;
    }

    private static function neutralSummary(?string $summary): ?string
    {
        if ($summary === null) {
            return null;
        }

        return in_array(mb_strtolower(trim($summary)), self::NEUTRAL_SUMMARIES, true) ? mb_substr(trim($summary), 0, 100) : null;
    }
}
