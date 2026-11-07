<?php

namespace App\Domain\Calendar\Http;

/** Esito di SafeHttpFetcher dopo gli eventuali redirect. */
final readonly class FetchResponse
{
    public function __construct(
        public int $status,
        public string $body,
        public ?string $etag,
        public ?string $lastModified,
        public ?int $retryAfterSeconds,
        public int $redirects,
    ) {}

    public function isNotModified(): bool
    {
        return $this->status === 304;
    }

    public function isSuccessful(): bool
    {
        return $this->status >= 200 && $this->status < 300;
    }
}
