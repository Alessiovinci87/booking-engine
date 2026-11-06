<?php

namespace App\Domain\Tenancy\Exceptions;

use LogicException;

final class TenantContextConflict extends LogicException
{
    public static function make(): self
    {
        return new self('Un contesto tenant è già attivo con un tenant diverso.');
    }
}
