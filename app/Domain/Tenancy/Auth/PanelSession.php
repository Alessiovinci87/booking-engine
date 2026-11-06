<?php

namespace App\Domain\Tenancy\Auth;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Session\Session;

/**
 * Stato di sicurezza della sessione del pannello (08): istante del login, ultima attività, MFA
 * verificata, ultima conferma della password. Solo timestamp, nessun segreto.
 */
final class PanelSession
{
    private const AUTHENTICATED_AT = 'panel.authenticated_at';

    private const LAST_ACTIVITY_AT = 'panel.last_activity_at';

    private const MFA_VERIFIED_AT = 'panel.mfa_verified_at';

    private const PASSWORD_CONFIRMED_AT = 'panel.password_confirmed_at';

    public function __construct(private readonly Session $session) {}

    public function start(): void
    {
        $now = CarbonImmutable::now()->getTimestamp();

        $this->session->put(self::AUTHENTICATED_AT, $now);
        $this->session->put(self::LAST_ACTIVITY_AT, $now);
        $this->session->forget([self::MFA_VERIFIED_AT, self::PASSWORD_CONFIRMED_AT]);
    }

    public function markMfaVerified(): void
    {
        $this->session->put(self::MFA_VERIFIED_AT, CarbonImmutable::now()->getTimestamp());
    }

    public function isMfaVerified(): bool
    {
        return is_int($this->session->get(self::MFA_VERIFIED_AT));
    }

    public function markPasswordConfirmed(): void
    {
        $this->session->put(self::PASSWORD_CONFIRMED_AT, CarbonImmutable::now()->getTimestamp());
    }

    public function passwordConfirmedWithin(int $seconds): bool
    {
        $at = $this->session->get(self::PASSWORD_CONFIRMED_AT);

        return is_int($at) && CarbonImmutable::now()->getTimestamp() - $at < $seconds;
    }

    /**
     * La sessione è scaduta per inattività o per durata assoluta (08: 2 h e 12 h).
     */
    public function isExpired(int $idleSeconds, int $absoluteSeconds): bool
    {
        $now = CarbonImmutable::now()->getTimestamp();
        $authenticatedAt = $this->session->get(self::AUTHENTICATED_AT);
        $lastActivityAt = $this->session->get(self::LAST_ACTIVITY_AT);

        if (! is_int($authenticatedAt) || ! is_int($lastActivityAt)) {
            return true;
        }

        return $now - $lastActivityAt >= $idleSeconds || $now - $authenticatedAt >= $absoluteSeconds;
    }

    public function touch(): void
    {
        $this->session->put(self::LAST_ACTIVITY_AT, CarbonImmutable::now()->getTimestamp());
    }
}
