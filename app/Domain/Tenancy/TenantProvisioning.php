<?php

namespace App\Domain\Tenancy;

use App\Domain\Tenancy\Audit\AuditActor;
use App\Domain\Tenancy\Audit\AuditLogger;
use App\Domain\Tenancy\Enums\MemberRole;
use App\Domain\Tenancy\Exceptions\TenantProvisioningFailed;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\Models\TenantMember;
use App\Domain\Tenancy\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Creazione di un tenant con il suo proprietario (01: nessuna registrazione pubblica, solo comando
 * interno). L'utente nasce senza MFA: al primo accesso il pannello impone la configurazione (08).
 *
 * Tenant e membership si scrivono dentro il contesto del nuovo tenant, come ogni altra riga
 * soggetta a RLS; l'utente è globale.
 */
final class TenantProvisioning
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly AuditLogger $audit,
    ) {}

    public static function slugIsValid(string $slug): bool
    {
        return preg_match('/^[a-z0-9]([a-z0-9-]{1,61}[a-z0-9])?$/', $slug) === 1;
    }

    public function slugIsTaken(string $slug): bool
    {
        return DB::selectOne('select app_tenant_by_slug(?) as id', [$slug])->id !== null;
    }

    public function emailIsTaken(string $email): bool
    {
        return User::query()->whereRaw('lower(email) = ?', [User::normalizeEmail($email)])->exists();
    }

    /**
     * @return array{tenant: Tenant, owner: User}
     */
    public function create(string $slug, string $name, string $ownerEmail, string $password): array
    {
        if (! self::slugIsValid($slug)) {
            throw TenantProvisioningFailed::invalidSlug();
        }

        if ($this->slugIsTaken($slug)) {
            throw TenantProvisioningFailed::slugTaken();
        }

        if ($this->emailIsTaken($ownerEmail)) {
            throw TenantProvisioningFailed::emailTaken();
        }

        $tenantId = (string) Str::uuid7();

        return $this->tenantContext->run($tenantId, function () use ($tenantId, $slug, $name, $ownerEmail, $password): array {
            $owner = new User;
            $owner->forceFill([
                'email' => User::normalizeEmail($ownerEmail),
                'password_hash' => $password,
            ])->save();

            $tenant = new Tenant;
            $tenant->forceFill(['id' => $tenantId, 'name' => trim($name), 'slug' => $slug])->save();

            $member = new TenantMember;
            $member->forceFill(['user_id' => $owner->id, 'role' => MemberRole::OWNER])->save();

            $this->audit->record('tenant.created', 'tenant', $tenantId, ['slug' => $slug], actor: AuditActor::system());
            $this->audit->record('tenant_member.created', 'tenant_member', $member->id, ['role' => MemberRole::OWNER->value], actor: AuditActor::system());

            return ['tenant' => $tenant->refresh(), 'owner' => $owner];
        });
    }
}
