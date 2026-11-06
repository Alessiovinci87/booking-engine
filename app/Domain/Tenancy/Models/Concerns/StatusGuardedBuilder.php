<?php

namespace App\Domain\Tenancy\Models\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Builder dei modelli con macchina a stati: un update di massa che tocca status è ammesso solo da una
 * classe in app/Domain/<Area>/StateMachines (GuardsStatusWrites).
 *
 * @template TModel of Model
 *
 * @extends Builder<TModel>
 */
final class StatusGuardedBuilder extends Builder
{
    /**
     * @param  array<mixed>  $values
     */
    public function update(array $values): int
    {
        if (array_key_exists('status', $values) || array_key_exists($this->model->qualifyColumn('status'), $values)) {
            StatusWriteGuard::assertAllowed($this->model::class);
        }

        return parent::update($values);
    }
}
