<?php

namespace App\Domain\Calendar\Http;

/**
 * Trasporto di SafeHttpFetcher con cURL: solo HTTPS, connessione all'IP già validato
 * (CURLOPT_RESOLVE, così il nome non viene risolto una seconda volta), nessun redirect automatico,
 * nessun proxy dall'ambiente, nessun cookie, corpo letto in streaming fino al limite, timeout totale.
 */
final class CurlTransport implements HttpTransport
{
    public const USER_AGENT = 'BookingEngine-CalendarSync/1.0';

    public function get(string $url, string $host, int $port, string $ip, array $headers, int $maxBytes, int $timeoutSeconds): RawResponse
    {
        $body = '';
        $tooLarge = false;
        $responseHeaders = [];
        $resolveIp = str_contains($ip, ':') ? '['.$ip.']' : $ip;
        $requestHeaders = [];

        foreach ($headers as $name => $value) {
            $requestHeaders[] = $name.': '.$value;
        }

        if ($url === '') {
            throw new \InvalidArgumentException('URL vuoto.');
        }

        $handle = curl_init();
        curl_setopt_array($handle, [
            CURLOPT_URL => $url,
            CURLOPT_RESOLVE => [$host.':'.$port.':'.$resolveIp],
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROXY => '',
            CURLOPT_NOPROXY => '*',
            CURLOPT_COOKIEFILE => '',
            CURLOPT_HTTPGET => true,
            CURLOPT_USERAGENT => self::USER_AGENT,
            CURLOPT_HTTPHEADER => $requestHeaders,
            CURLOPT_ENCODING => '',
            CURLOPT_CONNECTTIMEOUT => $timeoutSeconds,
            CURLOPT_TIMEOUT => $timeoutSeconds,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HEADERFUNCTION => function ($curl, string $line) use (&$responseHeaders): int {
                $parts = explode(':', $line, 2);

                if (count($parts) === 2) {
                    $responseHeaders[strtolower(trim($parts[0]))] = trim($parts[1]);
                }

                return strlen($line);
            },
            CURLOPT_WRITEFUNCTION => function ($curl, string $chunk) use (&$body, &$tooLarge, $maxBytes): int {
                if (strlen($body) + strlen($chunk) > $maxBytes) {
                    $tooLarge = true;

                    return 0;
                }

                $body .= $chunk;

                return strlen($chunk);
            },
        ]);

        $ok = curl_exec($handle);
        $errno = curl_errno($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        curl_close($handle);

        if ($tooLarge) {
            throw new FetchFailed(FetchError::TOO_LARGE);
        }

        if ($ok === false) {
            throw new FetchFailed(match ($errno) {
                CURLE_OPERATION_TIMEDOUT => FetchError::TIMEOUT,
                // SSL_CONNECT_ERROR, PEER_FAILED_VERIFICATION (51 e 60), SSL_CERTPROBLEM, SSL_CACERT_BADFILE
                35, 51, 58, 60, 77 => FetchError::TLS_ERROR,
                default => FetchError::CONNECTION_FAILED,
            });
        }

        return new RawResponse($status, $responseHeaders, $body);
    }
}
