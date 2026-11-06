<?php

namespace App\Domain\Tenancy\Models\Concerns;

use App\Domain\Tenancy\Exceptions\IllegalStatusWrite;

/**
 * Verifica che una scrittura di status avvenga dentro una classe di app/Domain/<Area>/StateMachines,
 * cercando nello stack delle chiamate.
 */
final class StatusWriteGuard
{
    public const STATE_MACHINE_NAMESPACE = '/^App\\\\Domain\\\\[A-Za-z]+\\\\StateMachines\\\\/';

    public static function assertAllowed(string $modelClass): void
    {
        if (! self::calledFromStateMachine()) {
            throw IllegalStatusWrite::outsideStateMachine($modelClass);
        }
    }

    public static function calledFromStateMachine(): bool
    {
        foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS) as $frame) {
            if (isset($frame['class']) && preg_match(self::STATE_MACHINE_NAMESPACE, $frame['class']) === 1) {
                return true;
            }
        }

        return false;
    }
}
