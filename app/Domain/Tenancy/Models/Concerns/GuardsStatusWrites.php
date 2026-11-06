<?php

namespace App\Domain\Tenancy\Models\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Protezione a runtime della regola 5 di CLAUDE.md (03, "Regole generali"; decisione dopo M0):
 * sui modelli con macchina a stati la colonna status si scrive solo da una classe in
 * app/Domain/<Area>/StateMachines.
 *
 * Intercetta l'assegnazione diretta dell'attributo, fill, forceFill, create, l'update del modello
 * e, con il builder StatusGuardedBuilder (attributo UseEloquentBuilder sul modello), gli update di
 * massa tramite Eloquent. Le scritture con DB::table() sono coperte
 * dal controllo statico tests/Arch/StateTransitionsTest.
 *
 * @mixin Model
 */
trait GuardsStatusWrites
{
    /**
     * @param  string  $key
     * @param  mixed  $value
     * @return mixed
     */
    public function setAttribute($key, $value)
    {
        if ($key === 'status') {
            StatusWriteGuard::assertAllowed(static::class);
        }

        return parent::setAttribute($key, $value);
    }

    public static function bootGuardsStatusWrites(): void
    {
        // Difesa aggiuntiva: uno stato cambiato senza passare da setAttribute (es. setRawAttributes)
        // non viene salvato fuori da una macchina a stati.
        static::saving(function (Model $model): void {
            if ($model->isDirty('status')) {
                StatusWriteGuard::assertAllowed($model::class);
            }
        });
    }
}
