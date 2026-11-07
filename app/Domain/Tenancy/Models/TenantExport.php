<?php

namespace App\Domain\Tenancy\Models;

use App\Domain\Tenancy\Enums\TenantExportStatus;
use Carbon\CarbonImmutable;

/**
 * Export completo del tenant per l'uscita del cliente (7.1; 09 "Uscita del cliente" punto 2; 07
 * POST /admin/tenant/export). Il file è privato (disco di config/offboarding.php) e si scarica con un
 * link firmato e la ri-autenticazione, per 30 giorni. Gli stati cambiano solo da
 * TenantExportStateMachine.
 *
 * @property string $id
 * @property string|null $requested_by
 * @property TenantExportStatus $status
 * @property string|null $file_path
 * @property int|null $size_bytes
 * @property int|null $future_reservations
 * @property CarbonImmutable|null $completed_at
 * @property CarbonImmutable|null $expires_at
 */
class TenantExport extends TenantModel
{
    /** @var list<string> */
    protected $hidden = ['file_path'];

    protected function casts(): array
    {
        return [
            'status' => TenantExportStatus::class,
            'size_bytes' => 'integer',
            'future_reservations' => 'integer',
            'completed_at' => 'immutable_datetime',
            'expires_at' => 'immutable_datetime',
        ];
    }

    public function isDownloadable(): bool
    {
        return $this->status === TenantExportStatus::READY && $this->expires_at !== null && $this->expires_at->isFuture();
    }
}
