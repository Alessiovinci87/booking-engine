<?php

use App\Domain\Calendar\Http\FetchFailed;
use App\Domain\Calendar\Http\HostResolver;
use App\Domain\Calendar\Http\HttpTransport;
use App\Domain\Calendar\Http\RawResponse;
use App\Domain\Calendar\IcalImportService;
use App\Domain\Calendar\Models\ExternalCalendar;
use App\Domain\Calendar\Models\ExternalCalendarEvent;
use App\Domain\Calendar\Models\SyncRun;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/*
 * Supporto ai test dei calendari (M5): DNS e trasporto HTTP finti per SafeHttpFetcher, calendari iCal
 * generati sul modello dei feed di export pubblici di Booking e Airbnb.
 *
 * I formati sono IPOTESI da confermare nella verifica di fase 0 sui feed reali (06, ADR-0007):
 * - Booking: PRODID "-//Booking.com//...", UID "...@booking.com", SUMMARY "CLOSED - Not available",
 *   eventi a giornata intera;
 * - Airbnb: PRODID "-//Airbnb Inc//Hosting Calendar...", UID "...@airbnb.com", SUMMARY "Reserved" o
 *   "Airbnb (Not available)", DESCRIPTION con URL della prenotazione e ultime cifre del telefono.
 */

final class FakeResolver implements HostResolver
{
    /** @param array<string, list<string>> $map */
    public function __construct(public array $map = []) {}

    public function resolve(string $host): array
    {
        return $this->map[$host] ?? [];
    }
}

final class FakeTransport implements HttpTransport
{
    /** @var list<array{url: string, host: string, ip: string, headers: array<string, string>, max: int, timeout: int}> */
    public array $calls = [];

    /** @param array<string, RawResponse|FetchFailed> $responses per URL */
    public function __construct(public array $responses = []) {}

    public function get(string $url, string $host, int $port, string $ip, array $headers, int $maxBytes, int $timeoutSeconds): RawResponse
    {
        $this->calls[] = ['url' => $url, 'host' => $host, 'ip' => $ip, 'headers' => $headers, 'max' => $maxBytes, 'timeout' => $timeoutSeconds];
        $response = $this->responses[$url] ?? new RawResponse(404, [], '');

        if ($response instanceof FetchFailed) {
            throw $response;
        }

        return $response;
    }
}

/** Collega al container un trasporto finto; gli host *.example.com risolvono a un IP pubblico. */
function fakeCalendarHttp(): FakeTransport
{
    $transport = new FakeTransport;
    $resolver = new class implements HostResolver
    {
        public function resolve(string $host): array
        {
            return match (true) {
                str_starts_with($host, 'internal.') => ['10.0.0.8'],
                str_ends_with($host, '.example.com') => ['93.184.216.34'],
                default => [],
            };
        }
    };

    app()->instance(HostResolver::class, $resolver);
    app()->instance(HttpTransport::class, $transport);

    return $transport;
}

/**
 * Risposta 200 con un calendario.
 *
 * @param  array<string, string>  $headers
 */
function icsResponse(string $body, array $headers = []): RawResponse
{
    return new RawResponse(200, $headers, $body);
}

/**
 * Calendario nello stile del feed di export di un'OTA (ipotesi, vedi sopra).
 *
 * @param  list<array{uid?: string|null, in: string, out: string, summary?: string, extra?: string}>  $events  date YYYY-MM-DD
 */
function otaIcs(string $style, array $events): string
{
    $lines = ['BEGIN:VCALENDAR', 'VERSION:2.0', 'CALSCALE:GREGORIAN'];
    $lines[] = $style === 'airbnb' ? 'PRODID:-//Airbnb Inc//Hosting Calendar 1.0//EN' : 'PRODID:-//Booking.com//Booking.com Calendar 1.0//EN';

    foreach ($events as $i => $event) {
        $lines[] = 'BEGIN:VEVENT';
        $lines[] = 'DTSTAMP:20270101T000000Z';
        $lines[] = 'DTSTART;VALUE=DATE:'.str_replace('-', '', $event['in']);
        $lines[] = 'DTEND;VALUE=DATE:'.str_replace('-', '', $event['out']);

        if (! array_key_exists('uid', $event) || $event['uid'] !== null) {
            $lines[] = 'UID:'.($event['uid'] ?? ($style === 'airbnb' ? 'abc'.$i.'-'.md5((string) $i).'@airbnb.com' : md5((string) $i).'@booking.com'));
        }

        $lines[] = 'SUMMARY:'.($event['summary'] ?? ($style === 'airbnb' ? 'Reserved' : 'CLOSED - Not available'));

        if ($style === 'airbnb' && ($event['summary'] ?? 'Reserved') === 'Reserved') {
            $lines[] = 'DESCRIPTION:Reservation URL: https://www.airbnb.com/hosting/reservations/details/HMABC'.$i.'\nPhone Number (Last 4 Digits): 1234';
        }

        if (isset($event['extra'])) {
            $lines[] = $event['extra'];
        }

        $lines[] = 'END:VEVENT';
    }

    $lines[] = 'END:VCALENDAR';

    return implode("\r\n", $lines)."\r\n";
}

/** Data futura relativa a oggi (YYYY-MM-DD). */
function futureDay(int $offset): string
{
    return CarbonImmutable::now('Europe/Rome')->addDays($offset)->toDateString();
}

/** Calendario importato sull'unità (stato iniziale ACTIVE dal default della tabella). */
function createCalendar(string $tenantId, string $unitId, string $source = 'BOOKING', string $url = 'https://ical.example.com/export.ics'): ExternalCalendar
{
    return inTenant($tenantId, function () use ($unitId, $source, $url): ExternalCalendar {
        $calendar = new ExternalCalendar;
        $calendar->id = (string) Str::uuid7();
        $calendar->forceFill(['unit_id' => $unitId, 'source' => $source, 'url_encrypted' => $url])->save();

        return $calendar->refresh();
    });
}

/**
 * Tenant con struttura (fuso Europe/Rome), unità e un calendario importato; trasporto HTTP finto.
 *
 * @return array{tenant: string, unit: string, calendar: ExternalCalendar, http: FakeTransport, url: string}
 */
function calendarFixture(string $source = 'BOOKING'): array
{
    $tenant = createTenantRow();
    $unit = createUnitRow($tenant, createPropertyRow($tenant));
    $url = 'https://ical.example.com/'.strtolower($source).'/'.Str::random(12).'.ics';

    return ['tenant' => $tenant, 'unit' => $unit, 'calendar' => createCalendar($tenant, $unit, $source, $url), 'http' => fakeCalendarHttp(), 'url' => $url];
}

/** Esegue una sincronizzazione del calendario della fixture. */
function syncCalendar(array $fx): ?SyncRun
{
    return app(IcalImportService::class)->sync($fx['tenant'], $fx['calendar']->id);
}

/**
 * Eventi del calendario della fixture, per UID.
 *
 * @return array<string, ExternalCalendarEvent>
 */
function calendarEvents(array $fx): array
{
    return inTenant($fx['tenant'], fn (): array => ExternalCalendarEvent::query()
        ->where('external_calendar_id', $fx['calendar']->id)->get()->keyBy('uid')->all());
}

function freshCalendar(array $fx): ExternalCalendar
{
    return inTenant($fx['tenant'], fn (): ExternalCalendar => ExternalCalendar::query()->findOrFail($fx['calendar']->id));
}
