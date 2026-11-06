<?php

namespace App\Domain\Tenancy\Models\Concerns;

use Illuminate\Database\Eloquent\Model;

/**
 * Versione per la concorrenza ottimistica (07, "If-Match"): cresce di uno a ogni salvataggio con
 * modifiche. bumpVersion() la incrementa anche quando cambiano solo le righe figlie (traduzioni).
 *
 * @property int $version
 */
trait HasVersion
{
    public static function bootHasVersion(): void
    {
        static::updating(function (Model $model): void {
            if (! $model->isDirty('version')) {
                $model->setAttribute('version', (int) $model->getOriginal('version') + 1);
            }
        });
    }

    public function bumpVersion(): void
    {
        $this->setAttribute('version', (int) $this->getOriginal('version') + 1);
        $this->save();
    }
}
