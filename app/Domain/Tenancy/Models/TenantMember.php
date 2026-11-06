<?php

namespace App\Domain\Tenancy\Models;

use App\Domain\Tenancy\Enums\MemberRole;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Membership di un utente in un tenant (02). Nella 1.0 si crea solo OWNER (08).
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $user_id
 * @property MemberRole $role
 */
class TenantMember extends TenantModel
{
    protected function casts(): array
    {
        return ['role' => MemberRole::class];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
