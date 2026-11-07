<?php

namespace App\Domain\Tenancy\Auth;

use App\Domain\Tenancy\Audit\AuditActor;
use App\Domain\Tenancy\Audit\AuditLogger;
use App\Domain\Tenancy\Models\User;
use App\Domain\Tenancy\Models\UserPasswordReset;
use App\Domain\Tenancy\Outbox\Outbox;
use App\Domain\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Cache\RateLimiter;
use Illuminate\Contracts\Hashing\Hasher;
use Illuminate\Support\Facades\DB;

/**
 * Gestione della password del gestore (6.11, ADR-0016).
 *
 * - **Password dimenticata**: la risposta è sempre la stessa, che l'email esista o no. Limite per email
 *   (in hash) e per IP. Il link è monouso, vale 60 minuti e nel database c'è solo l'hash del token (più
 *   la copia cifrata per l'outbox, azzerata all'uso). Un nuovo link annulla i precedenti.
 * - **Reimpostazione**: nuova password con la politica di M1; il link diventa usato; tutte le sessioni
 *   si chiudono. Non apre una sessione e non tocca la MFA: si torna al login e serve comunque il codice
 *   (o un codice di recupero).
 * - **Cambio password** dal pannello: password attuale, MFA già verificata (middleware), chiusura delle
 *   altre sessioni; la sessione corrente prende atto della revoca (PanelSession).
 *
 * In tutti i casi: audit senza valori personali ed email di avviso tramite outbox (solo ID nel payload).
 */
final class PasswordManagement
{
    public function __construct(
        private readonly RateLimiter $limiter,
        private readonly Hasher $hasher,
        private readonly AuditLogger $audit,
        private readonly UserTenants $userTenants,
        private readonly TenantContext $tenantContext,
        private readonly Outbox $outbox,
    ) {}

    /**
     * Richiesta del link. Restituisce i secondi di attesa se il limite è superato, altrimenti null. Il
     * limite si conta su ogni richiesta, anche per email inesistenti: il risultato non rivela nulla.
     */
    public function requestReset(string $email, ?string $ip): ?int
    {
        $emailKey = 'password-reset:email:'.hash('sha256', User::normalizeEmail($email));
        $ipKey = 'password-reset:ip:'.hash('sha256', (string) $ip);
        $decay = (int) config('security.password_reset.decay_seconds');

        if ($this->limiter->tooManyAttempts($emailKey, (int) config('security.password_reset.per_email'))
            || $this->limiter->tooManyAttempts($ipKey, (int) config('security.password_reset.per_ip'))) {
            return max(1, $this->limiter->availableIn($emailKey), $this->limiter->availableIn($ipKey));
        }

        $this->limiter->hit($emailKey, $decay);
        $this->limiter->hit($ipKey, $decay);

        $user = $this->findUser($email);

        if ($user === null) {
            $this->audit->record('auth.password_reset.requested', actor: AuditActor::anonymous());

            return null;
        }

        $tenantId = $this->userTenants->primaryOf($user->id);

        DB::transaction(function () use ($user, $tenantId): void {
            $now = CarbonImmutable::now();
            $this->invalidateOpenTokens($user->id, $now);

            $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
            $reset = new UserPasswordReset;
            $reset->forceFill([
                'user_id' => $user->id,
                'token_hash' => self::hashToken($token),
                'token_encrypted' => $token,
                'expires_at' => $now->addMinutes((int) config('security.password_reset.ttl_minutes')),
            ])->save();
            $id = $reset->id;

            $this->audit->record('auth.password_reset.requested', 'user', $user->id, tenantId: $tenantId, actor: AuditActor::anonymous());

            // L'outbox è per tenant (02): senza tenant (account di sola piattaforma) nessuna email.
            if ($tenantId !== null) {
                $this->tenantContext->run($tenantId, fn () => $this->outbox->enqueue(
                    'account.password_reset',
                    'account.password_reset:'.$id,
                    ['user_id' => $user->id, 'password_reset_id' => $id],
                ));
            }
        });

        return null;
    }

    /**
     * Reimpostazione con il token del link. false se il token non esiste, è scaduto o è già stato usato
     * (un unico esito, PASSWORD_RESET_INVALID). La password è già validata dal chiamante.
     */
    public function reset(string $token, string $password): bool
    {
        return DB::transaction(function () use ($token, $password): bool {
            $now = CarbonImmutable::now();
            $row = UserPasswordReset::query()
                ->where('token_hash', self::hashToken($token))
                ->lockForUpdate()
                ->first();

            if ($row === null) {
                $this->audit->record('auth.password_reset.failed', actor: AuditActor::anonymous());

                return false;
            }

            $tenantId = $this->userTenants->primaryOf($row->user_id);

            if ($row->used_at !== null || $row->expires_at->lessThanOrEqualTo($now)) {
                $row->forceFill(['token_encrypted' => null])->save();
                $this->audit->record('auth.password_reset.failed', 'user', $row->user_id, ['reason' => $row->used_at !== null ? 'used' : 'expired'], tenantId: $tenantId, actor: AuditActor::anonymous());

                return false;
            }

            $user = User::query()->lockForUpdate()->findOrFail($row->user_id);

            $row->forceFill(['used_at' => $now, 'token_encrypted' => null])->save();
            $this->invalidateOpenTokens($user->id, $now);
            $revokedAt = $this->setPassword($user, $password);

            $this->audit->record('auth.password_reset.completed', 'user', $user->id, tenantId: $tenantId, actor: AuditActor::anonymous());
            $this->notifyChanged($user, $tenantId, $revokedAt);

            return true;
        });
    }

    /**
     * Cambio password dal pannello. Restituisce l'istante della revoca delle sessioni, di cui la sessione
     * corrente deve prendere atto per restare aperta.
     */
    public function change(User $user, string $password): CarbonImmutable
    {
        return DB::transaction(function () use ($user, $password): CarbonImmutable {
            $tenantId = $this->userTenants->primaryOf($user->id);
            $this->invalidateOpenTokens($user->id, CarbonImmutable::now());
            $revokedAt = $this->setPassword($user, $password);

            $this->audit->record('auth.password_changed', 'user', $user->id, tenantId: $tenantId, actor: AuditActor::user($user->id));
            $this->notifyChanged($user, $tenantId, $revokedAt);

            return $revokedAt;
        });
    }

    public function checkCurrentPassword(User $user, string $password): bool
    {
        return $this->hasher->check($password, $user->password_hash);
    }

    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    /**
     * Nuova password (hash Argon2id dal cast del modello) e revoca di tutte le sessioni aperte. Istante
     * al secondo: è la precisione con cui viene salvato e confrontato (PanelSession).
     */
    private function setPassword(User $user, string $password): CarbonImmutable
    {
        $revokedAt = CarbonImmutable::now()->startOfSecond();
        $user->forceFill(['password_hash' => $password, 'sessions_revoked_at' => $revokedAt])->save();

        return $revokedAt;
    }

    private function notifyChanged(User $user, ?string $tenantId, CarbonImmutable $at): void
    {
        if ($tenantId === null) {
            return;
        }

        $this->tenantContext->run($tenantId, fn () => $this->outbox->enqueue(
            'account.password_changed',
            'account.password_changed:'.$user->id.':'.$at->getTimestamp(),
            ['user_id' => $user->id],
        ));
    }

    private function invalidateOpenTokens(string $userId, CarbonImmutable $now): void
    {
        UserPasswordReset::query()
            ->where('user_id', $userId)
            ->whereNull('used_at')
            ->where('expires_at', '>', $now)
            ->update(['expires_at' => $now, 'token_encrypted' => null, 'updated_at' => $now]);
    }

    private function findUser(string $email): ?User
    {
        return User::query()->whereRaw('lower(email) = ?', [User::normalizeEmail($email)])->first();
    }
}
