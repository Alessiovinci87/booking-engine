<?php

namespace App\Domain\Tenancy\Models;

use App\Domain\Tenancy\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Base dei modelli tenant-scoped: UUID v7 generati dall'applicazione (HasUuids di Laravel 13 usa
 * Str::uuid7), tenant_id dal contesto, timestamp UTC.
 *
 * id e tenant_id non sono mai assegnabili in massa.
 */
abstract class TenantModel extends Model
{
    use BelongsToTenant;
    use HasUuids;

    /** @var list<string> */
    protected $guarded = ['id', 'tenant_id'];
}
