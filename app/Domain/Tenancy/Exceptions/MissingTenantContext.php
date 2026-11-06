<?php

namespace App\Domain\Tenancy\Exceptions;

use LogicException;

/**
 * Scrittura di un modello tenant-scoped fuori da TenantContext::run(), o con un tenant_id diverso dal
 * contesto. È sempre un errore del codice.
 */
final class MissingTenantContext extends LogicException
{
    public static function make(): self
    {
        return new self('Scrittura di un modello tenant-scoped senza contesto tenant.');
    }

    public static function mismatch(): self
    {
        return new self('tenant_id del modello diverso dal contesto tenant.');
    }
}
