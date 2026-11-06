<?php

namespace App\Domain\Tenancy\Exceptions;

use RuntimeException;

/**
 * Creazione del tenant rifiutata. reason() è la chiave di traduzione del motivo.
 */
final class TenantProvisioningFailed extends RuntimeException
{
    private function __construct(private readonly string $reasonKey)
    {
        parent::__construct($reasonKey);
    }

    public static function invalidSlug(): self
    {
        return new self('tenancy.create.errors.invalid_slug');
    }

    public static function slugTaken(): self
    {
        return new self('tenancy.create.errors.slug_taken');
    }

    public static function emailTaken(): self
    {
        return new self('tenancy.create.errors.email_taken');
    }

    public function reason(): string
    {
        return $this->reasonKey;
    }
}
