<?php

namespace App\Domain\Tenancy\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Codice di recupero MFA monouso, salvato solo come hash (08).
 *
 * @property string $id
 * @property string $user_id
 * @property string $code_hash
 * @property CarbonImmutable|null $used_at
 */
class UserRecoveryCode extends Model
{
    use HasUuids;

    /** @var list<string> */
    protected $fillable = ['code_hash', 'used_at'];

    /** @var list<string> */
    protected $hidden = ['code_hash'];

    protected function casts(): array
    {
        return ['used_at' => 'immutable_datetime'];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
