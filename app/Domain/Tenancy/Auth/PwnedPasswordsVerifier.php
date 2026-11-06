<?php

namespace App\Domain\Tenancy\Auth;

use Illuminate\Contracts\Validation\UncompromisedVerifier;
use Illuminate\Http\Client\Factory as HttpFactory;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Controllo delle password compromesse (08) con Have I Been Pwned, k-anonymity: si invia solo il
 * prefisso di 5 caratteri dello SHA-1, mai la password né l'hash completo.
 *
 * Fail-open (decisione dopo la verifica di M1): se il servizio non risponde la password viene
 * accettata, ma ogni mancata risposta produce un avviso nel log, senza password, hash né email.
 *
 * Chiama solo un URL fisso, mai un URL fornito dagli utenti: è un'eccezione esplicita del controllo
 * Arch/ExternalHttpTest (ADR-0009).
 */
final class PwnedPasswordsVerifier implements UncompromisedVerifier
{
    private const ENDPOINT = 'https://api.pwnedpasswords.com/range/';

    public function __construct(
        private readonly HttpFactory $http,
        private readonly LoggerInterface $logger,
        private readonly int $timeoutSeconds = 30,
    ) {}

    /**
     * @param  array{value: mixed, threshold: int}  $data
     */
    public function verify($data): bool
    {
        $value = (string) $data['value'];

        if ($value === '') {
            return false;
        }

        $hash = strtoupper(sha1($value));
        $prefix = substr($hash, 0, 5);

        foreach ($this->suffixes($prefix) as $line) {
            [$suffix, $count] = array_pad(explode(':', trim($line)), 2, '0');

            if ($prefix.$suffix === $hash && (int) $count > $data['threshold']) {
                return false;
            }
        }

        return true;
    }

    /** @return list<string> */
    private function suffixes(string $prefix): array
    {
        try {
            $response = $this->http->withHeaders(['Add-Padding' => 'true'])
                ->timeout($this->timeoutSeconds)
                ->get(self::ENDPOINT.$prefix);
        } catch (Throwable $e) {
            $this->unavailable(['reason' => 'connection', 'exception' => $e::class]);

            return [];
        }

        if (! $response->successful()) {
            $this->unavailable(['reason' => 'http_status', 'status' => $response->status()]);

            return [];
        }

        return array_values(array_filter(
            explode("\n", trim($response->body())),
            static fn (string $line): bool => str_contains($line, ':'),
        ));
    }

    /** @param array<string, string|int> $context */
    private function unavailable(array $context): void
    {
        $this->logger->warning('Controllo password compromesse non disponibile: password accettata senza verifica (fail-open).', $context);
    }
}
