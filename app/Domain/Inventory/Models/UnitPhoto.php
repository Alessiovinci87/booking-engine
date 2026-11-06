<?php

namespace App\Domain\Inventory\Models;

use App\Domain\Tenancy\Models\TenantModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Foto dell'unità (02; upload con URL firmati in 2.2). Il percorso non contiene dati personali.
 *
 * @property string $id
 * @property string $unit_id
 * @property string $disk
 * @property string $path
 * @property string $mime_type
 * @property int $size_bytes
 * @property int $position
 */
class UnitPhoto extends TenantModel
{
    protected function casts(): array
    {
        return [
            'size_bytes' => 'integer',
            'width' => 'integer',
            'height' => 'integer',
            'position' => 'integer',
        ];
    }

    /** @return BelongsTo<Unit, $this> */
    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }
}
