<?php

namespace App\Domain\Tenancy\Models;

use App\Domain\Tenancy\Enums\AuditActorType;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * Riga dell'audit log (02, 08): append-only, scritta solo da AuditLogger. changes non contiene mai
 * valori di campi personali (09).
 *
 * tenant_id è NULL solo per gli eventi di piattaforma senza tenant (es. login con email sconosciuta).
 *
 * @property string $id
 * @property string|null $tenant_id
 * @property AuditActorType $actor_type
 * @property string|null $actor_id
 * @property string $action
 * @property string|null $subject_type
 * @property string|null $subject_id
 * @property array<string, mixed> $changes
 * @property string|null $ip
 * @property string|null $user_agent
 * @property CarbonImmutable $occurred_at
 */
class AuditLog extends Model
{
    use HasUuids;

    protected $table = 'audit_log';

    public $timestamps = false;

    /** @var list<string> */
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'actor_type' => AuditActorType::class,
            'changes' => 'array',
            'occurred_at' => 'immutable_datetime',
        ];
    }

    public static function bootAuditLog(): void
    {
        // Append-only anche a livello applicativo (il database revoca UPDATE e DELETE ad app_runtime).
        static::updating(fn () => throw new LogicException('L\'audit log è append-only.'));
        static::deleting(fn () => throw new LogicException('L\'audit log è append-only.'));
    }
}
