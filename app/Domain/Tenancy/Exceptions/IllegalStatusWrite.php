<?php

namespace App\Domain\Tenancy\Exceptions;

use LogicException;

/**
 * Scrittura di status fuori da una macchina a stati (CLAUDE.md regola 5). È sempre un bug.
 */
final class IllegalStatusWrite extends LogicException
{
    public static function outsideStateMachine(string $modelClass): self
    {
        return new self(sprintf(
            'Lo stato di %s si modifica solo da una classe in app/Domain/<Area>/StateMachines.',
            class_basename($modelClass),
        ));
    }
}
