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

    /** Inizio della sessione in microsecondi, per il confronto con `users.sessions_revoked_at`. */
    private const AUTHENTICATED_AT_US = 'panel.authenticated_at_us';

    private const LAST_ACTIVITY_AT = 'panel.last_activity_at';

    private const MFA_VERIFIED_AT = 'panel.mfa_verified_at';

    private const PASSWORD_CONFIRMED_AT = 'panel.password_confirmed_at';

    private const REVOCATION_ACK = 'panel.revocation_ack';

    public function __construct(private readonly Session $session) {}

    public function start(): void
    {
        $instant = CarbonImmutable::now();
        $now = $instant->getTimestamp();

        $this->session->put(self::AUTHENTICATED_AT, $now);
        $this->session->put(self::AUTHENTICATED_AT_US, self::microseconds($instant));
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

    /**
     * La sessione è iniziata non dopo la revoca delle sessioni dell'utente (6.10 user:reset-mfa, 6.11
     * password). Confronto al microsecondo (ADR-0016): una sessione aperta subito dopo la revoca resta
     * aperta. Una sessione senza l'istante in microsecondi (aperta prima di questa versione) usa l'inizio
     * del suo secondo: nel dubbio si chiude.
     */
    public function startedNotAfter(CarbonImmutable $revokedAt): bool
    {
        $startedUs = $this->session->get(self::AUTHENTICATED_AT_US);
        $authenticatedAt = $this->session->get(self::AUTHENTICATED_AT);

        if (! is_int($startedUs)) {
            if (! is_int($authenticatedAt)) {
                return true;
            }

            $startedUs = $authenticatedAt * 1_000_000;
        }

        return $startedUs <= self::microseconds($revokedAt);
    }

    /**
     * La sessione corrente prende atto di una revoca che ha causato lei stessa (6.11, cambio password):
     * resta aperta, con la MFA già verificata, mentre tutte le altre sessioni si chiudono.
     */
    public function acknowledgeRevocation(CarbonImmutable $revokedAt): void
    {
        $this->session->put(self::REVOCATION_ACK, self::microseconds($revokedAt));
    }

    /** La revoca indicata è quella di cui questa sessione ha preso atto (vedi acknowledgeRevocation). */
    public function hasAcknowledged(CarbonImmutable $revokedAt): bool
    {
        return $this->session->get(self::REVOCATION_ACK) === self::microseconds($revokedAt);
    }

    private static function microseconds(CarbonImmutable $instant): int
    {
        return (int) $instant->format('Uu');
    }

    public function touch(): void
    {
        $this->session->put(self::LAST_ACTIVITY_AT, CarbonImmutable::now()->getTimestamp());
    }
}
