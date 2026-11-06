<?php

namespace App\Domain\Privacy\Models;

use App\Domain\Privacy\Enums\RetentionAction;
use App\Domain\Privacy\Enums\RetentionCategory;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Durata di conservazione per categoria di dati (02, 09). Configurazione globale della piattaforma:
 * nessuna durata è scritta nel codice (CLAUDE.md regola 17).
 *
 * @property string $id
 * @property RetentionCategory $category
 * @property int $retention_days
 * @property RetentionAction $action
 * @property string|null $legal_basis_note
 * @property string|null $validated_by
 * @property CarbonImmutable|null $validated_at
 */
class RetentionPolicy extends Model
{
    use HasUuids;

    /** @var list<string> */
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'category' => RetentionCategory::class,
            'retention_days' => 'integer',
            'action' => RetentionAction::class,
            'validated_at' => 'immutable_datetime',
        ];
    }
}
