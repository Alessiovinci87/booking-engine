<?php

namespace App\Domain\Tenancy\Exceptions;

use InvalidArgumentException;

final class InvalidTenantId extends InvalidArgumentException
{
    public static function make(): self
    {
        // Il valore non viene riportato nel messaggio: potrebbe provenire da input non verificato.
        return new self('Il tenant_id deve essere un UUID valido.');
    }
}
