<?php

namespace App\Domain\Tenancy\Support;

use App\Domain\Tenancy\Audit\AuditActor;
use App\Domain\Tenancy\Audit\AuditLogger;
use App\Domain\Tenancy\Auth\MfaReset;
use App\Domain\Tenancy\Database\DbRow;
use App\Domain\Tenancy\Enums\TenantStatus;
use App\Domain\Tenancy\Exceptions\BusinessRuleViolation;
use App\Domain\Tenancy\Models\SupportAccessGrant;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\Models\User;
use App\Domain\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Accesso di supporto in sola lettura (7.5; 08 "Accesso di supporto"; ADR-0018).
 *
 * - l'operatore è un utente con `is_platform_operator` e MFA attiva, senza membership;
 * - `support:open` crea un'autorizzazione a tempo (massimo 2 ore) con motivazione obbligatoria, senza
 *   dati personali come le motivazioni di MfaReset; una nuova autorizzazione chiude la precedente
 *   dello stesso operatore;
 * - durante l'autorizzazione il pannello del tenant è visibile in sola lettura (EnforceSupportAccess);
 * - gestore e piattaforma possono revocarla; l'audit (`support.opened`, `support.first_use`,
 *   `support.revoked`, `support.closed`) è nel tenant e quindi visibile al gestore.
 */
final class SupportAccessService
{
    public const MAX_HOURS = 2;

    public function __construct(
        private readonly TenantContext $tenants,
        private readonly AuditLogger $audit,
    ) {}

    public function findOperator(string $email): ?User
    {
        return User::query()->whereRaw('lower(email) = ?', [User::normalizeEmail($email)])->first();
    }

    /** Segna (o toglie) un utente come operatore di piattaforma. Un utente con membership non può esserlo. */
    public function setOperator(User $user, bool $operator): void
    {
        if ($operator && DbRow::int(DB::selectOne('select count(*) as n from app_user_tenants(?)', [$user->id]), 'n') > 0) {
            throw BusinessRuleViolation::make(409, 'SUPPORT_OPERATOR_HAS_TENANT');
        }

        $user->forceFill(['is_platform_operator' => $operator])->save();
        $this->audit->record($operator ? 'support.operator_added' : 'support.operator_removed', 'user', $user->id, actor: AuditActor::system());
    }

    public function open(string $tenantId, User $operator, string $reason, int $hours, ?CarbonImmutable $now = null): SupportAccessGrant
    {
        $now ??= CarbonImmutable::now();
        $reason = trim($reason);

        $refusal = match (true) {
            ! $operator->is_platform_operator => 'SUPPORT_NOT_OPERATOR',
            ! $operator->hasMfaEnabled() => 'SUPPORT_MFA_REQUIRED',
            $hours < 1 || $hours > self::MAX_HOURS => 'SUPPORT_INVALID_DURATION',
            ! MfaReset::reasonIsValid($reason) => 'SUPPORT_INVALID_REASON',
            default => null,
        };

        if ($refusal !== null) {
            throw BusinessRuleViolation::make(422, $refusal);
        }

        // Una sola autorizzazione attiva per operatore: la precedente (anche di un altro tenant) si chiude.
        $previous = DB::selectOne('select grant_id, tenant_id from app_support_grant_for(?, ?)', [$operator->id, $now->toIso8601ZuluString('microsecond')]);

        if ($previous !== null) {
            $this->close(DbRow::string($previous, 'tenant_id'), $now, DbRow::string($previous, 'grant_id'));
        }

        return $this->tenants->run($tenantId, function () use ($operator, $reason, $hours, $now): SupportAccessGrant {
            if (Tenant::query()->value('status') === TenantStatus::CLOSED) {
                throw BusinessRuleViolation::make(409, 'TENANT_CLOSED');
            }

            $grant = new SupportAccessGrant;
            $grant->id = (string) Str::uuid7();
            $grant->forceFill([
                'operator_user_id' => $operator->id,
                'reason' => $reason,
                'expires_at' => $now->addHours($hours),
                'created_at' => $now,
                'updated_at' => $now,
            ])->save();

            $this->audit->record('support.opened', 'support_access_grant', $grant->id, [
                'reason' => $reason,
                'hours' => $hours,
                'expires_at' => $grant->expires_at->toIso8601ZuluString(),
            ], actor: AuditActor::system());

            return $grant;
        });
    }

    /**
     * Chiude le autorizzazioni attive del tenant (comando support:close), o solo quella indicata.
     *
     * @return int autorizzazioni chiuse
     */
    public function close(string $tenantId, ?CarbonImmutable $now = null, ?string $grantId = null): int
    {
        $now ??= CarbonImmutable::now();

        return $this->tenants->run($tenantId, function () use ($now, $grantId): int {
            $grants = SupportAccessGrant::query()
                ->whereNull('revoked_at')
                ->where('expires_at', '>', $now)
                ->when($grantId !== null, fn ($q) => $q->whereKey($grantId))
                ->lockForUpdate()
                ->get();

            foreach ($grants as $grant) {
                $grant->forceFill(['revoked_at' => $now])->save();
                $this->audit->record('support.closed', 'support_access_grant', $grant->id, actor: AuditActor::system());
            }

            return $grants->count();
        });
    }

    /** Revoca dal pannello (dentro il contesto tenant della richiesta). */
    public function revoke(string $grantId, string $userId, ?CarbonImmutable $now = null): SupportAccessGrant
    {
        $now ??= CarbonImmutable::now();
        $grant = SupportAccessGrant::query()->lockForUpdate()->findOrFail($grantId);

        if (! $grant->isActive($now)) {
            throw BusinessRuleViolation::make(409, 'SUPPORT_ACCESS_NOT_ACTIVE');
        }

        $grant->forceFill(['revoked_at' => $now, 'revoked_by' => $userId])->save();
        $this->audit->record('support.revoked', 'support_access_grant', $grant->id, actor: AuditActor::user($userId));

        return $grant;
    }

    /**
     * Autorizzazione attiva dell'operatore, tramite la funzione SECURITY DEFINER (il tenant non è ancora noto).
     *
     * @return array{grant_id: string, tenant_id: string}|null
     */
    public function activeGrantFor(string $userId, ?CarbonImmutable $now = null): ?array
    {
        $row = DB::selectOne('select grant_id, tenant_id from app_support_grant_for(?, ?)', [$userId, ($now ?? CarbonImmutable::now())->toIso8601ZuluString('microsecond')]);

        return $row === null ? null : ['grant_id' => DbRow::string($row, 'grant_id'), 'tenant_id' => DbRow::string($row, 'tenant_id')];
    }

    /** Prima e ultima lettura dell'operatore; la prima lascia anche una riga di audit. */
    public function recordUse(string $tenantId, string $grantId, string $operatorId, ?CarbonImmutable $now = null): void
    {
        $now ??= CarbonImmutable::now();

        $this->tenants->run($tenantId, function () use ($grantId, $operatorId, $now): void {
            $grant = SupportAccessGrant::query()->find($grantId);

            if ($grant === null) {
                return;
            }

            $first = $grant->first_used_at === null;
            $grant->forceFill(['last_used_at' => $now, 'first_used_at' => $grant->first_used_at ?? $now])->save();

            if ($first) {
                $this->audit->record('support.first_use', 'support_access_grant', $grant->id, actor: AuditActor::user($operatorId));
            }
        });
    }
}
