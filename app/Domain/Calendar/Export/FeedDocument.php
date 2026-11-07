<?php

namespace App\Domain\Calendar\Export;

/** Documento iCal pronto da servire, con ETag forte calcolato sul contenuto (06 "Formato"). */
final readonly class FeedDocument
{
    public string $etag;

    public function __construct(public string $body, public int $events)
    {
        $this->etag = '"'.substr(hash('sha256', $body), 0, 32).'"';
    }
}
