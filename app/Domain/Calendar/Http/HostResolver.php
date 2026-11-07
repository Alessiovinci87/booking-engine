<?php

namespace App\Domain\Calendar\Http;

/**
 * Risoluzione DNS fatta da noi prima della connessione (anti DNS rebinding): SafeHttpFetcher valida gli
 * indirizzi restituiti e si collega a quello scelto. Sostituibile nei test.
 */
interface HostResolver
{
    /** @return list<string> indirizzi IPv4 e IPv6 del nome; vuoto se non risolvibile */
    public function resolve(string $host): array;
}
