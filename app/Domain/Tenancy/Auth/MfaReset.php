<?php

namespace App\Domain\Tenancy\Auth;

use App\Domain\Tenancy\Audit\AuditActor;
use App\Domain\Tenancy\Audit\AuditLogger;
use App\Domain\Tenancy\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Azzeramento della MFA di un utente del pannello che ha perso app e codici di recupero (6.10,
 * `php artisan user:reset-mfa`). Solo dalla piattaforma, dopo aver verificato l'identità per telefono.
 *
 * - segreto TOTP e codici di recupero cancellati: al prossimo accesso la MFA va riconfigurata (08:
 *   nessuna operazione admin senza MFA, la configurazione è possibile solo finché non è attiva);
 * - tutte le sessioni aperte si chiudono alla richiesta successiva (`sessions_revoked_at`);
 * - audit `user.mfa_reset` in ogni tenant dell'utente, visibile al gestore: solo ID e motivazione.
 */
final class MfaReset
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly UserTenants $tenants,
    ) {}

    public function reset(User $user, string $reason): void
    {
        DB::transaction(function () use ($user, $reason): void {
            DB::table('user_recovery_codes')->where('user_id', $user->id)->delete();
            $user->forceFill([
                'mfa_secret_encrypted' => null,
                'mfa_enabled_at' => null,
                'sessions_revoked_at' => CarbonImmutable::now(),
            ])->save();

            $tenantIds = $this->tenants->of($user->id);

            foreach ($tenantIds === [] ? [null] : $tenantIds as $tenantId) {
                $this->audit->record('user.mfa_reset', 'user', $user->id, ['reason' => $reason], tenantId: $tenantId, actor: AuditActor::system());
            }
        });
    }

    /**
     * Motivazione dell'audit: obbligatoria, breve e senza dati personali (08, 17 di CLAUDE.md). Rifiuta
     * indirizzi email e sequenze di cifre da numero di telefono.
     */
    public static function reasonIsValid(string $reason): bool
    {
        $length = mb_strlen($reason);

        return $length >= 10 && $length <= 200 && ! str_contains($reason, '@') && preg_match('/\d[\d\s.\-]{5,}\d/', $reason) !== 1;
    }
}
