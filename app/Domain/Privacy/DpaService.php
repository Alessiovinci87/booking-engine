<?php

namespace App\Domain\Privacy;

use App\Domain\Tenancy\Audit\AuditLogger;
use App\Domain\Tenancy\Models\Tenant;
use Carbon\CarbonImmutable;

/**
 * DPA art. 28 con la struttura (09 requisito 1): firmato prima dell'attivazione, cioè prima di
 * pubblicare una struttura (guardia `DPA_NOT_SIGNED`, ADR-0014). La firma si registra dalla
 * piattaforma (`php artisan tenant:dpa`), non dal pannello del gestore, che la vede in sola lettura.
 * Esegue nel contesto del tenant.
 */
final class DpaService
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function record(string $version, CarbonImmutable $signedAt): Tenant
    {
        $tenant = Tenant::query()->lockForUpdate()->firstOrFail();
        $previous = $tenant->dpa_version;

        $tenant->dpa_version = $version;
        $tenant->dpa_signed_at = $signedAt;
        $tenant->save();

        $this->audit->record('tenant.dpa_recorded', 'tenant', $tenant->id, [
            'dpa_version' => ['from' => $previous, 'to' => $version],
            'dpa_signed_at' => $signedAt->toIso8601ZuluString(),
        ]);

        return $tenant;
    }

    public static function isSigned(?Tenant $tenant): bool
    {
        return $tenant !== null && $tenant->dpa_signed_at !== null && $tenant->dpa_version !== null && $tenant->dpa_version !== '';
    }
}
