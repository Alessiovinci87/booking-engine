<?php

namespace App\Domain\Calendar\Http;

use RuntimeException;

/** Download rifiutato o fallito. Il messaggio non contiene mai l'URL (può contenere un token dell'OTA). */
final class FetchFailed extends RuntimeException
{
    public function __construct(public readonly FetchError $error)
    {
        parent::__construct('Download non riuscito: '.$error->value);
    }
}
