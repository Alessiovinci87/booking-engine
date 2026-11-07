<?php

namespace App\Domain\Calendar\Http;

/** Risposta di una singola richiesta: header con nomi in minuscolo. */
final readonly class RawResponse
{
    /**
     * @param  array<string, string>  $headers
     */
    public function __construct(
        public int $status,
        public array $headers,
        public string $body,
    ) {}

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }
}
