<?php

namespace App\Domain\Tenancy;

use App\Domain\Tenancy\Audit\AuditLogger;
use App\Domain\Tenancy\Enums\TenantStatus;
use App\Domain\Tenancy\Exceptions\BusinessRuleViolation;
use App\Domain\Tenancy\Models\Tenant;
use Carbon\CarbonImmutable;

/**
 * Stato del tenant (02: ACTIVE, SUSPENDED, OFFBOARDING, CLOSED). Non è una macchina a stati di 03: si
 * cambia solo da qui (11, attività 2.5; eccezione esplicita di Arch/StateTransitionsTest). Un tenant non
 * ACTIVE non vende: la pagina pubblica e i preventivi rispondono 404. Uscita del cliente (7.1):
 * ACTIVE o SUSPENDED → OFFBOARDING (`offboarding_started_at`), poi OFFBOARDING → CLOSED dopo la
 * cancellazione dei dati (`data_purged_at`); la procedura completa è in OffboardingService.
 */
final class TenantLifecycleService
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function suspend(Tenant $tenant): Tenant
    {
        return $this->transition($tenant, [TenantStatus::ACTIVE], TenantStatus::SUSPENDED);
    }

    public function reactivate(Tenant $tenant): Tenant
    {
        return $this->transition($tenant, [TenantStatus::SUSPENDED], TenantStatus::ACTIVE);
    }

    public function startOffboarding(Tenant $tenant): Tenant
    {
        $tenant->forceFill(['offboarding_started_at' => CarbonImmutable::now()]);

        return $this->transition($tenant, [TenantStatus::ACTIVE, TenantStatus::SUSPENDED], TenantStatus::OFFBOARDING);
    }

    public function close(Tenant $tenant): Tenant
    {
        $tenant->forceFill(['data_purged_at' => CarbonImmutable::now()]);

        return $this->transition($tenant, [TenantStatus::OFFBOARDING], TenantStatus::CLOSED);
    }

    /**
     * @param  list<TenantStatus>  $from
     */
    private function transition(Tenant $tenant, array $from, TenantStatus $to): Tenant
    {
        if (! in_array($tenant->status, $from, true)) {
            throw BusinessRuleViolation::make(409, 'ILLEGAL_TRANSITION', ['from' => $tenant->status->value, 'to' => $to->value]);
        }

        $previous = $tenant->status;
        $tenant->status = $to;
        $tenant->save();
        $this->audit->record('tenant.status_changed', 'tenant', $tenant->id, ['from' => $previous->value, 'to' => $to->value], $tenant->id);

        return $tenant;
    }
}
