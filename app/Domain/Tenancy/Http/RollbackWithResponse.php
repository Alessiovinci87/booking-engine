<?php

namespace App\Domain\Tenancy\Http;

use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Annulla la transazione del contesto tenant restituendo comunque la risposta d'errore già pronta.
 * Uso interno di WithTenantContext.
 */
final class RollbackWithResponse extends RuntimeException
{
    public function __construct(public readonly Response $response)
    {
        parent::__construct('rollback');
    }
}
