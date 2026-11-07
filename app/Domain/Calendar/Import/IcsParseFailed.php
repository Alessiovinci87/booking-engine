<?php

namespace App\Domain\Calendar\Import;

use RuntimeException;

/** ICS non leggibile (06 "Elaborazione", punto 1): la sincronizzazione finisce in errore senza modifiche. */
final class IcsParseFailed extends RuntimeException
{
    public const INVALID_ICS = 'INVALID_ICS';

    public const TOO_MANY_EVENTS = 'TOO_MANY_EVENTS';

    public function __construct(public readonly string $errorCode)
    {
        parent::__construct('Calendario iCal non valido: '.$errorCode);
    }
}
