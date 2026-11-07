<?php

namespace App\Domain\Tenancy\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Autorizzazione a tempo per l'accesso di supporto in sola lettura (7.5; 08 "Accesso di supporto";
 * ADR-0018). Si crea solo con il comando support:open (SupportAccessService), dura al massimo 2 ore e
 * si chiude alla scadenza o con la revoca del gestore o della piattaforma.
 *
 * @property string $id
 * @property string|null $operator_user_id
 * @property string $reason
 * @property CarbonImmutable $expires_at
 * @property CarbonImmutable|null $revoked_at
 * @property string|null $revoked_by
 * @property CarbonImmutable|null $first_used_at
 * @property CarbonImmutable|null $last_used_at
 * @property-read User|null $operator
 */
class SupportAccessGrant extends TenantModel
{
    protected function casts(): array
    {
        return [
            'expires_at' => 'immutable_datetime',
            'revoked_at' => 'immutable_datetime',
            'first_used_at' => 'immutable_datetime',
            'last_used_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function operator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'operator_user_id');
    }

    public function isActive(?CarbonImmutable $now = null): bool
    {
        return $this->revoked_at === null && $this->expires_at->isAfter($now ?? CarbonImmutable::now());
    }
}
