<?php

namespace App\Domain\Calendar;

use App\Domain\Calendar\Http\FetchError;
use App\Domain\Calendar\Http\FetchFailed;
use App\Domain\Calendar\Http\FetchResponse;
use App\Domain\Calendar\Http\HostResolver;
use App\Domain\Calendar\Http\HttpTransport;
use App\Domain\Calendar\Http\IpPolicy;

/**
 * Unico client HTTP per URL forniti dagli utenti (06, 08; CLAUDE.md regola 10), attività 5.1:
 *
 * - solo `https` sulla porta 443, senza credenziali nell'URL;
 * - risoluzione DNS fatta da noi; se uno degli indirizzi restituiti è bloccato (IpPolicy) si rifiuta
 *   tutto, poi ci si collega proprio all'indirizzo validato (anti DNS rebinding);
 * - al massimo 3 redirect, ciascuno validato da capo;
 * - 10 secondi, 2 MB letti in streaming, nessun cookie, user agent fisso, solo header condizionali.
 *
 * L'URL non compare mai in log ed eccezioni: può contenere il token privato dell'OTA.
 */
final class SafeHttpFetcher
{
    public const MAX_BYTES = 2 * 1024 * 1024;

    public const TIMEOUT_SECONDS = 10;

    public const MAX_REDIRECTS = 3;

    private const ALLOWED_HEADERS = ['if-none-match', 'if-modified-since'];

    public function __construct(
        private readonly HostResolver $resolver,
        private readonly HttpTransport $transport,
    ) {}

    /**
     * Controlla un URL senza scaricarlo (salvataggio di un calendario importato).
     *
     * @throws FetchFailed
     */
    public function assertAllowed(string $url): void
    {
        $this->target($url);
    }

    /**
     * @param  array<string, string>  $conditionalHeaders  If-None-Match / If-Modified-Since
     *
     * @throws FetchFailed
     */
    public function get(string $url, array $conditionalHeaders = []): FetchResponse
    {
        $headers = [];

        foreach ($conditionalHeaders as $name => $value) {
            if (in_array(strtolower($name), self::ALLOWED_HEADERS, true) && $value !== '') {
                $headers[$name] = str_replace(["\r", "\n"], '', $value);
            }
        }

        $current = $url;

        for ($redirects = 0; ; $redirects++) {
            [$host, $port, $ip] = $this->target($current);
            $response = $this->transport->get($current, $host, $port, $ip, $headers, self::MAX_BYTES, self::TIMEOUT_SECONDS);

            if (! in_array($response->status, [301, 302, 303, 307, 308], true)) {
                $retryAfter = $response->header('retry-after');

                return new FetchResponse(
                    $response->status,
                    $response->body,
                    $response->header('etag'),
                    $response->header('last-modified'),
                    $retryAfter !== null && ctype_digit($retryAfter) ? (int) $retryAfter : null,
                    $redirects,
                );
            }

            if ($redirects >= self::MAX_REDIRECTS) {
                throw new FetchFailed(FetchError::TOO_MANY_REDIRECTS);
            }

            $location = $response->header('location');

            if ($location === null || $location === '') {
                throw new FetchFailed(FetchError::INVALID_URL);
            }

            $current = self::absolute($current, $location);
        }
    }

    /**
     * @return array{0: string, 1: int, 2: string} host, porta, IP validato
     */
    private function target(string $url): array
    {
        if (strlen($url) > 2048 || preg_match('/[\s\x00-\x1f]/', $url) === 1) {
            throw new FetchFailed(FetchError::INVALID_URL);
        }

        $parts = parse_url($url);

        if ($parts === false || ! isset($parts['scheme'], $parts['host']) || isset($parts['user']) || isset($parts['pass'])) {
            throw new FetchFailed(FetchError::INVALID_URL);
        }

        if (strtolower($parts['scheme']) !== 'https') {
            throw new FetchFailed(FetchError::SCHEME_NOT_ALLOWED);
        }

        $port = $parts['port'] ?? 443;

        if ($port !== 443) {
            throw new FetchFailed(FetchError::PORT_NOT_ALLOWED);
        }

        $host = strtolower(trim($parts['host'], '[]'));

        if ($host === '' || $host === 'localhost' || str_ends_with($host, '.localhost') || str_ends_with($host, '.internal') || str_ends_with($host, '.local')) {
            throw new FetchFailed(FetchError::BLOCKED_ADDRESS);
        }

        $ips = $this->resolver->resolve($host);

        if ($ips === []) {
            throw new FetchFailed(FetchError::DNS_FAILED);
        }

        foreach ($ips as $ip) {
            if (IpPolicy::isBlocked($ip)) {
                throw new FetchFailed(FetchError::BLOCKED_ADDRESS);
            }
        }

        return [$host, $port, $ips[0]];
    }

    private static function absolute(string $base, string $location): string
    {
        if (preg_match('#^[a-z][a-z0-9+.-]*://#i', $location) === 1) {
            return $location;
        }

        $parts = parse_url($base);
        $origin = 'https://'.($parts['host'] ?? '');

        if (str_starts_with($location, '//')) {
            return 'https:'.$location;
        }

        if (str_starts_with($location, '/')) {
            return $origin.$location;
        }

        $path = $parts['path'] ?? '/';
        $directory = substr($path, 0, (int) strrpos($path, '/') + 1);

        return $origin.$directory.$location;
    }
}
