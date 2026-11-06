<?php

namespace App\Domain\Tenancy\Audit;

use App\Domain\Tenancy\Enums\AuditActorType;

/**
 * Autore di un'azione registrata nell'audit: solo tipo e ID, mai nome o email.
 */
final class AuditActor
{
    public function __construct(
        public readonly AuditActorType $type,
        public readonly ?string $id = null,
    ) {}

    public static function user(string $userId): self
    {
        return new self(AuditActorType::USER, $userId);
    }

    public static function system(): self
    {
        return new self(AuditActorType::SYSTEM);
    }

    public static function anonymous(): self
    {
        return new self(AuditActorType::ANONYMOUS);
    }
}
