<?php

namespace App\Domain\Tenancy\Models\Concerns;

use App\Domain\Tenancy\Exceptions\MissingTenantContext;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Modelli tenant-scoped (08, "Livello applicativo"): global scope su tenant_id come difesa aggiuntiva
 * a RLS (non come unica barriera) e tenant_id assegnato solo da TenantContext, mai dal client.
 *
 * Fuori da un contesto tenant lo scope non aggiunge filtri: RLS restituisce comunque 0 righe.
 *
 * @mixin Model
 */
trait BelongsToTenant
{
    public static function bootBelongsToTenant(): void
    {
        static::addGlobalScope('tenant', function (Builder $builder): void {
            $tenantId = app(TenantContext::class)->currentTenantId();

            if ($tenantId !== null) {
                $builder->where($builder->getModel()->qualifyColumn('tenant_id'), $tenantId);
            }
        });

        static::creating(function (Model $model): void {
            $tenantId = app(TenantContext::class)->currentTenantId();

            if ($tenantId === null) {
                throw MissingTenantContext::make();
            }

            // Il tenant viene sempre dal contesto: un valore diverso è un errore del codice.
            $current = $model->getAttribute('tenant_id');

            if ($current !== null && strtolower((string) $current) !== $tenantId) {
                throw MissingTenantContext::mismatch();
            }

            $model->setAttribute('tenant_id', $tenantId);
        });
    }

    /** @return BelongsTo<Tenant, $this> */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
