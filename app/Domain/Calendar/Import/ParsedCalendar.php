<?php

namespace App\Domain\Calendar\Import;

/** Risultato della lettura di un calendario: eventi futuri validi e scartati contati (06). */
final readonly class ParsedCalendar
{
    /**
     * @param  list<ParsedEvent>  $events
     */
    public function __construct(
        public array $events,
        public int $recurringSkipped = 0,
        public int $invalidSkipped = 0,
    ) {}
}
