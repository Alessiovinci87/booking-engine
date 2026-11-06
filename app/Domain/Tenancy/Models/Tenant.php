<?php

namespace App\Domain\Tenancy\Models;

use App\Domain\Inventory\Models\Property;
use App\Domain\Payments\Models\StripeAccount;
use App\Domain\Tenancy\Casts\PostgresTextArray;
use App\Domain\Tenancy\Enums\Locale;
use App\Domain\Tenancy\Enums\TenantPlan;
use App\Domain\Tenancy\Enums\TenantStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Tenant (02). Non ha tenant_id: RLS lo rende visibile solo quando id = app_current_tenant().
 * Si crea solo con il comando interno tenant:create (01, nessuna registrazione pubblica).
 *
 * @property string $id
 * @property string $name
 * @property string $slug
 * @property TenantStatus $status
 * @property TenantPlan $plan
 * @property Locale $default_locale
 * @property string $timezone
 * @property list<string> $embed_allowed_origins
 * @property CarbonImmutable|null $dpa_signed_at
 */
class Tenant extends Model
{
    use HasUuids;

    /** @var list<string> */
    protected $fillable = ['name', 'slug', 'plan', 'default_locale', 'timezone', 'dpa_signed_at', 'dpa_version', 'embed_allowed_origins'];

    protected function casts(): array
    {
        return [
            'status' => TenantStatus::class,
            'plan' => TenantPlan::class,
            'default_locale' => Locale::class,
            'embed_allowed_origins' => PostgresTextArray::class,
            'dpa_signed_at' => 'immutable_datetime',
            'offboarding_started_at' => 'immutable_datetime',
            'channels_disconnected_confirmed_at' => 'immutable_datetime',
            'feeds_disabled_at' => 'immutable_datetime',
        ];
    }

    /** @return HasMany<TenantMember, $this> */
    public function members(): HasMany
    {
        return $this->hasMany(TenantMember::class);
    }

    /** @return HasMany<Property, $this> */
    public function properties(): HasMany
    {
        return $this->hasMany(Property::class);
    }

    /** @return HasOne<StripeAccount, $this> */
    public function stripeAccount(): HasOne
    {
        return $this->hasOne(StripeAccount::class);
    }
}
