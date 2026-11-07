<?php

use App\Domain\Calendar\Import\IcsParseFailed;
use App\Domain\Calendar\Import\IcsParser;
use Carbon\CarbonImmutable;

/*
 * 5.2 — Lettura degli ICS importati (06 "Elaborazione"): notti intere nel fuso della struttura,
 * ricorrenti ignorati, eventi passati ignorati, UID mancante, SUMMARY ridotto alle etichette neutre,
 * limite di 5000 eventi, ICS non valido.
 */

function parseIcs(string $body, string $tz = 'Europe/Rome', string $today = '2027-06-01')
{
    return (new IcsParser)->parse($body, $tz, CarbonImmutable::parse($today, $tz));
}

function vevents(string ...$events): string
{
    return "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//Test//EN\r\n".implode('', array_map(
        fn (string $e) => "BEGIN:VEVENT\r\n".$e."\r\nEND:VEVENT\r\n",
        $events,
    ))."END:VCALENDAR\r\n";
}

it('legge i feed nello stile di Booking e Airbnb (ipotesi di fase 0) a giornate intere', function () {
    $booking = otaIcs('booking', [['uid' => 'b1@booking.com', 'in' => '2027-06-10', 'out' => '2027-06-14']]);
    $airbnb = otaIcs('airbnb', [
        ['uid' => 'a1@airbnb.com', 'in' => '2027-06-17', 'out' => '2027-06-20'],
        ['uid' => 'a2@airbnb.com', 'in' => '2027-07-01', 'out' => '2027-07-03', 'summary' => 'Airbnb (Not available)'],
    ]);

    $b = parseIcs($booking)->events;
    $a = parseIcs($airbnb)->events;

    expect($b)->toHaveCount(1)
        ->and($b[0]->uid)->toBe('b1@booking.com')
        ->and((string) $b[0]->stay)->toBe('[2027-06-10,2027-06-14)')
        ->and($b[0]->summaryRedacted)->toBe('CLOSED - Not available')
        ->and($a)->toHaveCount(2)
        ->and($a[0]->summaryRedacted)->toBe('Reserved')
        ->and($a[1]->summaryRedacted)->toBe('Airbnb (Not available)');
});

it('converte gli orari nel fuso della struttura: fine dopo la mezzanotte occupa anche quella notte', function () {
    $parsed = parseIcs(vevents(
        // 22:30 UTC del 9 = 00:30 del 10 a Roma (ora legale); fine 08:00 UTC del 12 = 10:00 del 12
        "UID:t1\r\nDTSTART:20270609T223000Z\r\nDTEND:20270612T080000Z",
        // Orari locali con TZID, fine esattamente a mezzanotte
        "UID:t2\r\nDTSTART;TZID=Europe/Rome:20270615T150000\r\nDTEND;TZID=Europe/Rome:20270618T000000",
        // Nessun DTEND: un giorno
        "UID:t3\r\nDTSTART;VALUE=DATE:20270620",
        // DURATION
        "UID:t4\r\nDTSTART;VALUE=DATE:20270701\r\nDURATION:P3D",
    ))->events;

    expect(array_map(fn ($e) => (string) $e->stay, $parsed))->toBe([
        '[2027-06-10,2027-06-13)',
        '[2027-06-15,2027-06-18)',
        '[2027-06-20,2027-06-21)',
        '[2027-07-01,2027-07-04)',
    ]);
});

it('ignora ricorrenti, annullati, passati e senza DTSTART; UID mancante → hash stabile', function () {
    $body = vevents(
        "UID:r1\r\nDTSTART;VALUE=DATE:20270610\r\nDTEND;VALUE=DATE:20270611\r\nRRULE:FREQ=WEEKLY",
        "UID:c1\r\nDTSTART;VALUE=DATE:20270610\r\nDTEND;VALUE=DATE:20270611\r\nSTATUS:CANCELLED",
        "UID:p1\r\nDTSTART;VALUE=DATE:20270501\r\nDTEND;VALUE=DATE:20270601",
        "UID:x1\r\nSUMMARY:senza date",
        "DTSTART;VALUE=DATE:20270610\r\nDTEND;VALUE=DATE:20270612\r\nSUMMARY:Mario Rossi",
    );

    $first = parseIcs($body);
    $second = parseIcs($body);

    expect($first->events)->toHaveCount(1)
        ->and($first->recurringSkipped)->toBe(1)
        ->and($first->invalidSkipped)->toBe(1)
        ->and($first->events[0]->uid)->toStartWith('hash-')
        ->and($first->events[0]->uid)->toBe($second->events[0]->uid)
        // un SUMMARY che non è un'etichetta neutra può contenere un nome: non si conserva
        ->and($first->events[0]->summaryRedacted)->toBeNull();
});

it('ICS non valido o vuoto e oltre 5000 eventi', function () {
    expect(fn () => parseIcs('<html>errore</html>'))->toThrow(IcsParseFailed::class)
        ->and(fn () => parseIcs(''))->toThrow(IcsParseFailed::class)
        ->and(fn () => parseIcs("BEGIN:VCALENDAR\r\nBEGIN:VEVENT\r\nDTSTART:rotto"))->toThrow(IcsParseFailed::class)
        ->and(parseIcs("BEGIN:VCALENDAR\r\nVERSION:2.0\r\nEND:VCALENDAR\r\n")->events)->toBe([]);

    $many = vevents(...array_fill(0, 5001, "DTSTART;VALUE=DATE:20270610\r\nDTEND;VALUE=DATE:20270611"));

    try {
        parseIcs($many);
        $code = null;
    } catch (IcsParseFailed $e) {
        $code = $e->errorCode;
    }

    expect($code)->toBe(IcsParseFailed::TOO_MANY_EVENTS);
});
