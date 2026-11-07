<?php

namespace App\Domain\Tenancy\StateMachines;

use App\Domain\Reservations\Exceptions\IllegalTransition;
use App\Domain\Tenancy\Audit\AuditLogger;
use App\Domain\Tenancy\Enums\TenantExportStatus as S;
use App\Domain\Tenancy\Models\TenantExport;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/**
 * Stati dell'export completo del tenant (7.1):
 *
 * | Da | Evento | A |
 * |---|---|---|
 * | — | richiesta | PENDING |
 * | PENDING | file pronto | READY (`expires_at` = completamento + 30 giorni) |
 * | PENDING | errore definitivo | FAILED |
 * | READY | scadenza, ospite anonimizzato (09, I22), cancellazione dei dati del tenant | EXPIRED |
 *
 * Nell'audit solo ID, conteggi e date: mai il percorso del file né dati personali.
 */
final class TenantExportStateMachine
{
    public const VALID_DAYS = 30;

    public function __construct(private readonly AuditLogger $audit) {}

    public function request(?string $userId): TenantExport
    {
        $export = new TenantExport;
        $export->id = (string) Str::uuid7();
        $export->forceFill(['requested_by' => $userId, 'status' => S::PENDING])->save();
        $this->audit->record('tenant.export_requested', 'tenant_export', $export->id);

        return $export;
    }

    public function ready(TenantExport $export, string $path, int $size, int $futureReservations): void
    {
        $this->assertFrom($export, [S::PENDING], 'ready');
        $now = CarbonImmutable::now();
        $export->forceFill([
            'status' => S::READY,
            'file_path' => $path,
            'size_bytes' => $size,
            'future_reservations' => $futureReservations,
            'completed_at' => $now,
            'expires_at' => $now->addDays(self::VALID_DAYS),
        ])->save();
        $this->audit->record('tenant.export_ready', 'tenant_export', $export->id, ['size_bytes' => $size, 'future_reservations' => $futureReservations]);
    }

    public function fail(TenantExport $export): void
    {
        $this->assertFrom($export, [S::PENDING], 'fail');
        $export->forceFill(['status' => S::FAILED])->save();
        $this->audit->record('tenant.export_failed', 'tenant_export', $export->id);
    }

    public function expire(TenantExport $export, string $reason): void
    {
        $this->assertFrom($export, [S::READY, S::PENDING], 'expire');
        $export->forceFill(['status' => S::EXPIRED, 'file_path' => null])->save();
        $this->audit->record('tenant.export_expired', 'tenant_export', $export->id, ['reason' => $reason]);
    }

    /** @param  list<S>  $from */
    private function assertFrom(TenantExport $export, array $from, string $event): void
    {
        if (! in_array($export->status, $from, true)) {
            throw IllegalTransition::of('tenant_export', $export->status->value, $event);
        }
    }
}
