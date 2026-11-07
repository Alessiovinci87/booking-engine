<?php

namespace App\Domain\Calendar\Import;

use App\Domain\Reservations\StayRange;

/** Evento valido letto da un calendario importato (06). Il SUMMARY è già ridotto alle etichette neutre. */
final readonly class ParsedEvent
{
    public function __construct(
        public string $uid,
        public StayRange $stay,
        public ?string $summaryRedacted,
        public string $contentHash,
    ) {}
}
