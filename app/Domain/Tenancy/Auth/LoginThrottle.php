<?php

namespace App\Domain\Tenancy\Auth;

use App\Domain\Tenancy\Models\User;
use Illuminate\Cache\RateLimiter;

/**
 * Limite ai tentativi di accesso (07, 08): 5 ogni 15 minuti per account e per IP. Le chiavi non
 * contengono l'email in chiaro (hash), così i dati personali non finiscono in cache.
 *
 * Lo stesso limite vale per i codici MFA, per utente.
 */
final class LoginThrottle
{
    public function __construct(private readonly RateLimiter $limiter) {}

    public function tooManyLoginAttempts(string $email, ?string $ip): bool
    {
        return $this->limiter->tooManyAttempts($this->accountKey($email), $this->maxAttempts())
            || $this->limiter->tooManyAttempts($this->ipKey($ip), $this->maxAttempts());
    }

    public function hitLogin(string $email, ?string $ip): void
    {
        $this->limiter->hit($this->accountKey($email), $this->decay());
        $this->limiter->hit($this->ipKey($ip), $this->decay());
    }

    public function clearAccount(string $email): void
    {
        $this->limiter->clear($this->accountKey($email));
    }

    public function loginRetryAfter(string $email, ?string $ip): int
    {
        return max($this->limiter->availableIn($this->accountKey($email)), $this->limiter->availableIn($this->ipKey($ip)));
    }

    public function tooManyMfaAttempts(User $user): bool
    {
        return $this->limiter->tooManyAttempts($this->mfaKey($user), $this->maxAttempts());
    }

    public function hitMfa(User $user): void
    {
        $this->limiter->hit($this->mfaKey($user), $this->decay());
    }

    public function clearMfa(User $user): void
    {
        $this->limiter->clear($this->mfaKey($user));
    }

    public function mfaRetryAfter(User $user): int
    {
        return $this->limiter->availableIn($this->mfaKey($user));
    }

    private function accountKey(string $email): string
    {
        return 'login:account:'.hash('sha256', User::normalizeEmail($email));
    }

    private function ipKey(?string $ip): string
    {
        return 'login:ip:'.hash('sha256', (string) $ip);
    }

    private function mfaKey(User $user): string
    {
        return 'login:mfa:'.$user->id;
    }

    private function maxAttempts(): int
    {
        return (int) config('security.login.max_attempts');
    }

    private function decay(): int
    {
        return (int) config('security.login.decay_seconds');
    }
}
