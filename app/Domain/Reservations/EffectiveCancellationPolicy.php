<?php

namespace App\Domain\Reservations;

use App\Domain\Inventory\Models\Unit;
use App\Domain\Reservations\Models\CancellationPolicy;

/**
 * Politica di cancellazione che vale per un'unità (ADR-0009): quella dell'unità se presente,
 * altrimenti quella predefinita della struttura. Null solo per una struttura non ancora pubblicabile.
 */
final class EffectiveCancellationPolicy
{
    public function for(Unit $unit): ?CancellationPolicy
    {
        $id = $unit->cancellation_policy_id ?? $unit->property->default_cancellation_policy_id;

        return $id === null ? null : CancellationPolicy::query()->find($id);
    }
}
