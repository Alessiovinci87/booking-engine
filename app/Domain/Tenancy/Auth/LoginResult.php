<?php

namespace App\Domain\Tenancy\Auth;

use App\Domain\Tenancy\Models\User;

final class LoginResult
{
    private function __construct(
        public readonly ?User $user,
        public readonly bool $throttled,
        public readonly int $retryAfter = 0,
    ) {}

    public static function success(User $user): self
    {
        return new self($user, false);
    }

    public static function invalid(): self
    {
        return new self(null, false);
    }

    public static function throttled(int $retryAfter): self
    {
        return new self(null, true, $retryAfter);
    }

    public function succeeded(): bool
    {
        return $this->user !== null;
    }
}
