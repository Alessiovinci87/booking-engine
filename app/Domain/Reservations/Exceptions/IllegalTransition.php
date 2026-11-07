<?php

namespace App\Domain\Reservations\Exceptions;

use App\Domain\Tenancy\Exceptions\BusinessRuleViolation;

/**
 * Transizione non prevista dalla tabella di 03: verso il client 409 ILLEGAL_TRANSITION (07). Quando
 * nasce da un evento interno (job, webhook) è un bug e va segnalata.
 */
final class IllegalTransition extends BusinessRuleViolation
{
    public static function of(string $machine, ?string $from, string $event): self
    {
        return new self(409, 'ILLEGAL_TRANSITION', ['machine' => $machine, 'from' => $from, 'event' => $event]);
    }
}
