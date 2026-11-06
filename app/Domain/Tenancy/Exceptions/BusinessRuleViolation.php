<?php

namespace App\Domain\Tenancy\Exceptions;

use RuntimeException;

/**
 * Regola di dominio non rispettata, con il codice di errore di 07 e dettagli non personali. Il gestore
 * delle eccezioni la trasforma nella risposta { error: { code, details } } con lo stato indicato.
 */
class BusinessRuleViolation extends RuntimeException
{
    /**
     * @param  array<string, mixed>  $details
     */
    public function __construct(
        public readonly int $httpStatus,
        public readonly string $errorCode,
        public readonly array $details = [],
    ) {
        parent::__construct($errorCode);
    }

    /**
     * @param  array<string, mixed>  $details
     */
    public static function make(int $httpStatus, string $errorCode, array $details = []): self
    {
        return new self($httpStatus, $errorCode, $details);
    }
}
