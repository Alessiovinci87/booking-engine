<?php

namespace App\Domain\Calendar\Export;

use RuntimeException;

/**
 * Feed FROZEN senza più elementi: non si serve un calendario vuoto, che per l'OTA significherebbe
 * "tutto libero" (I19). L'endpoint risponde con un errore temporaneo e l'OTA mantiene l'ultima versione.
 */
final class FeedUnavailable extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Feed congelato senza elementi: non si serve vuoto (I19).');
    }
}
