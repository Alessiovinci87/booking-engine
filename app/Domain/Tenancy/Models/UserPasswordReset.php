<?php

namespace App\Domain\Tenancy\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Link monouso per reimpostare la password del pannello (6.11, ADR-0016): solo l'hash del token, più la
 * copia cifrata per l'email dell'outbox, azzerata all'uso, alla scadenza o alla sostituzione. Tabella
 * globale senza tenant_id. Le scritture passano da PasswordManagement.
 *
 * @property string $id
 * @property string $user_id
 * @property string $token_hash
 * @property string|null $token_encrypted
 * @property CarbonImmutable $expires_at
 * @property CarbonImmutable|null $used_at
 */
class UserPasswordReset extends Model
{
    use HasUuids;

    /** @var list<string> */
    protected $hidden = ['token_hash', 'token_encrypted'];

    protected function casts(): array
    {
        return [
            'token_encrypted' => 'encrypted',
            'expires_at' => 'immutable_datetime',
            'used_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Ancora utilizzabile: non usato e non scaduto. */
    public function isUsable(): bool
    {
        return $this->used_at === null && $this->token_encrypted !== null && $this->expires_at->isFuture();
    }
}
