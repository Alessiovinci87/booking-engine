<?php

namespace App\Domain\Calendar\Http;

/**
 * Una sola richiesta GET verso un indirizzo già validato, senza seguire i redirect. Sostituibile nei
 * test; in produzione CurlTransport.
 */
interface HttpTransport
{
    /**
     * @param  array<string, string>  $headers  solo header condizionali
     *
     * @throws FetchFailed TIMEOUT, TOO_LARGE, CONNECTION_FAILED, TLS_ERROR
     */
    public function get(string $url, string $host, int $port, string $ip, array $headers, int $maxBytes, int $timeoutSeconds): RawResponse;
}
