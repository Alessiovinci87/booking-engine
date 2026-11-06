<?php

namespace App\Domain\Calendar\Enums;

/** SyncRun.outcome (06, "Elaborazione"). */
enum SyncRunOutcome: string
{
    case SUCCESS = 'SUCCESS';
    case NOT_MODIFIED = 'NOT_MODIFIED';
    case ERROR = 'ERROR';
    case SUSPICIOUS = 'SUSPICIOUS';
}
