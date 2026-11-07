<?php

use App\Domain\Calendar\Http\FetchError;
use App\Domain\Calendar\Http\FetchFailed;
use App\Domain\Calendar\Http\IpPolicy;
use App\Domain\Calendar\Http\RawResponse;
use App\Domain\Calendar\SafeHttpFetcher;

/*
 * 5.1 — SafeHttpFetcher (06 "Configurazione e sicurezza", 08 "Protezione dell'import iCal"): solo https,
 * DNS risolto da noi e connessione all'IP validato, reti private e riservate bloccate, redirect
 * rivalidati (massimo 3), limiti di dimensione e tempo, solo header condizionali. DNS e trasporto finti.
 */

function fetcher(FakeResolver $resolver, FakeTransport $transport): SafeHttpFetcher
{
    return new SafeHttpFetcher($resolver, $transport);
}

function fetchError(callable $call): ?FetchError
{
    try {
        $call();
    } catch (FetchFailed $e) {
        return $e->error;
    }

    return null;
}

it('scarica con https collegandosi all\'IP risolto da noi, con limiti e user agent fissi', function () {
    $resolver = new FakeResolver(['ical.example.com' => ['93.184.216.34']]);
    $transport = new FakeTransport(['https://ical.example.com/cal.ics' => new RawResponse(200, ['etag' => '"v1"', 'last-modified' => 'Tue, 01 Jun 2027 10:00:00 GMT'], "BEGIN:VCALENDAR\r\nEND:VCALENDAR")]);

    $response = fetcher($resolver, $transport)->get('https://ical.example.com/cal.ics', ['If-None-Match' => '"v0"', 'Authorization' => 'Bearer x', 'Cookie' => 'a=b']);

    expect($response->status)->toBe(200)
        ->and($response->etag)->toBe('"v1"')
        ->and($response->lastModified)->toBe('Tue, 01 Jun 2027 10:00:00 GMT')
        ->and($transport->calls[0]['ip'])->toBe('93.184.216.34')
        ->and($transport->calls[0]['headers'])->toBe(['If-None-Match' => '"v0"'])
        ->and($transport->calls[0]['max'])->toBe(2 * 1024 * 1024)
        ->and($transport->calls[0]['timeout'])->toBe(10);
});

it('rifiuta schemi diversi da https, porte diverse da 443, credenziali nell\'URL e URL non validi', function (string $url, FetchError $error) {
    $resolver = new FakeResolver(['ical.example.com' => ['93.184.216.34']]);
    $transport = new FakeTransport;

    expect(fetchError(fn () => fetcher($resolver, $transport)->get($url)))->toBe($error)
        ->and($transport->calls)->toBe([]);
})->with([
    'http' => ['http://ical.example.com/cal.ics', FetchError::SCHEME_NOT_ALLOWED],
    'file' => ['file:///etc/passwd', FetchError::INVALID_URL],
    'gopher' => ['gopher://ical.example.com/', FetchError::SCHEME_NOT_ALLOWED],
    'porta' => ['https://ical.example.com:8443/cal.ics', FetchError::PORT_NOT_ALLOWED],
    'credenziali' => ['https://user:pass@ical.example.com/cal.ics', FetchError::INVALID_URL],
    'a capo' => ["https://ical.example.com/cal.ics\r\nHost: evil", FetchError::INVALID_URL],
    'localhost' => ['https://localhost/cal.ics', FetchError::BLOCKED_ADDRESS],
]);

it('blocca reti private, loopback, link-local, metadata, CGNAT, multicast e IPv6 riservati', function (string $ip) {
    $resolver = new FakeResolver(['ical.example.com' => [$ip]]);
    $transport = new FakeTransport;

    expect(fetchError(fn () => fetcher($resolver, $transport)->get('https://ical.example.com/cal.ics')))->toBe(FetchError::BLOCKED_ADDRESS)
        ->and($transport->calls)->toBe([]);
})->with(['127.0.0.1', '10.1.2.3', '172.16.5.4', '192.168.1.1', '169.254.169.254', '100.64.0.1', '0.0.0.0', '0.1.2.3', '224.0.0.1',
    '255.255.255.255', '::1', '::', 'fc00::1', 'fd12:3456::1', 'fe80::1', 'ff02::1', '::ffff:127.0.0.1', '::ffff:8.8.8.8', '64:ff9b::7f00:1']);

it('IP letterale nell\'URL: stessi controlli', function () {
    $transport = new FakeTransport;

    expect(fetchError(fn () => fetcher(new FakeResolver(['169.254.169.254' => ['169.254.169.254']]), $transport)->get('https://169.254.169.254/latest/meta-data')))
        ->toBe(FetchError::BLOCKED_ADDRESS)
        ->and(fetchError(fn () => fetcher(new FakeResolver(['::1' => ['::1']]), $transport)->get('https://[::1]/cal.ics')))->toBe(FetchError::BLOCKED_ADDRESS);
});

it('DNS rebinding: se uno solo degli indirizzi è privato si rifiuta tutto', function () {
    $resolver = new FakeResolver(['ical.example.com' => ['93.184.216.34', '10.0.0.5']]);

    expect(fetchError(fn () => fetcher($resolver, new FakeTransport)->get('https://ical.example.com/cal.ics')))->toBe(FetchError::BLOCKED_ADDRESS);
});

it('nome non risolvibile → DNS_FAILED', function () {
    expect(fetchError(fn () => fetcher(new FakeResolver, new FakeTransport)->get('https://nowhere.example.com/cal.ics')))->toBe(FetchError::DNS_FAILED);
});

it('redirect rivalidati: verso un IP privato rifiutato, verso http rifiutato, oltre 3 rifiutato', function () {
    $resolver = new FakeResolver(['a.example.com' => ['93.184.216.34'], 'internal.example.com' => ['10.0.0.7']]);

    $toPrivate = new FakeTransport(['https://a.example.com/cal.ics' => new RawResponse(302, ['location' => 'https://internal.example.com/secret'], '')]);
    expect(fetchError(fn () => fetcher($resolver, $toPrivate)->get('https://a.example.com/cal.ics')))->toBe(FetchError::BLOCKED_ADDRESS)
        ->and($toPrivate->calls)->toHaveCount(1);

    $toHttp = new FakeTransport(['https://a.example.com/cal.ics' => new RawResponse(301, ['location' => 'http://a.example.com/cal.ics'], '')]);
    expect(fetchError(fn () => fetcher($resolver, $toHttp)->get('https://a.example.com/cal.ics')))->toBe(FetchError::SCHEME_NOT_ALLOWED);

    $loop = new FakeTransport([
        'https://a.example.com/1' => new RawResponse(302, ['location' => '/2'], ''),
        'https://a.example.com/2' => new RawResponse(302, ['location' => '/3'], ''),
        'https://a.example.com/3' => new RawResponse(302, ['location' => '/4'], ''),
        'https://a.example.com/4' => new RawResponse(302, ['location' => '/5'], ''),
    ]);
    expect(fetchError(fn () => fetcher($resolver, $loop)->get('https://a.example.com/1')))->toBe(FetchError::TOO_MANY_REDIRECTS)
        ->and($loop->calls)->toHaveCount(4);
});

it('fino a 3 redirect validi vengono seguiti', function () {
    $resolver = new FakeResolver(['a.example.com' => ['93.184.216.34'], 'b.example.com' => ['93.184.216.35']]);
    $transport = new FakeTransport([
        'https://a.example.com/1' => new RawResponse(302, ['location' => 'https://b.example.com/2'], ''),
        'https://b.example.com/2' => new RawResponse(307, ['location' => 'cal.ics'], ''),
        'https://b.example.com/cal.ics' => new RawResponse(200, [], 'ok'),
    ]);

    $response = fetcher($resolver, $transport)->get('https://a.example.com/1');

    expect($response->body)->toBe('ok')->and($response->redirects)->toBe(2)
        ->and(array_column($transport->calls, 'ip'))->toBe(['93.184.216.34', '93.184.216.35', '93.184.216.35']);
});

it('corpo troppo grande, risposta lenta e Retry-After', function () {
    $resolver = new FakeResolver(['a.example.com' => ['93.184.216.34']]);

    expect(fetchError(fn () => fetcher($resolver, new FakeTransport(['https://a.example.com/big' => new FetchFailed(FetchError::TOO_LARGE)]))->get('https://a.example.com/big')))->toBe(FetchError::TOO_LARGE)
        ->and(fetchError(fn () => fetcher($resolver, new FakeTransport(['https://a.example.com/slow' => new FetchFailed(FetchError::TIMEOUT)]))->get('https://a.example.com/slow')))->toBe(FetchError::TIMEOUT);

    $limited = fetcher($resolver, new FakeTransport(['https://a.example.com/x' => new RawResponse(429, ['retry-after' => '120'], '')]))->get('https://a.example.com/x');
    expect($limited->status)->toBe(429)->and($limited->retryAfterSeconds)->toBe(120);
});

it('l\'URL non compare nel messaggio d\'errore', function () {
    try {
        fetcher(new FakeResolver(['a.example.com' => ['10.0.0.1']]), new FakeTransport)->get('https://a.example.com/export?token=segreto');
    } catch (FetchFailed $e) {
        expect($e->getMessage())->not->toContain('segreto')->not->toContain('a.example.com');
    }
});

it('IpPolicy ammette indirizzi pubblici', function () {
    expect(IpPolicy::isBlocked('93.184.216.34'))->toBeFalse()
        ->and(IpPolicy::isBlocked('2606:2800:220:1:248:1893:25c8:1946'))->toBeFalse()
        ->and(IpPolicy::isBlocked('non-un-ip'))->toBeTrue();
});
