<?php

namespace App\Domain\Reservations;

use App\Domain\Inventory\Models\Unit;
use App\Domain\Tenancy\Enums\Locale;
use App\Domain\Tenancy\Exceptions\BusinessRuleViolation;

/**
 * Snapshot copiati nella prenotazione alla creazione (02): la politica di cancellazione effettiva
 * dell'unità (ADR-0009) con i testi standard IT/EN e il fuso della struttura, che serve al calcolo
 * del rimborso (05: "00:00 del giorno di arrivo, fuso della struttura").
 */
final class ReservationSnapshots
{
    public function __construct(private readonly EffectiveCancellationPolicy $policies) {}

    /**
     * @return array{policy_id: string, code: string, timezone: string, texts: array<string, array{title: string, text: string}>}
     */
    public function cancellationPolicy(Unit $unit): array
    {
        $policy = $this->policies->for($unit);

        if ($policy === null) {
            throw BusinessRuleViolation::make(422, 'CANCELLATION_POLICY_MISSING');
        }

        $texts = [];

        foreach (Locale::cases() as $locale) {
            $texts[$locale->value] = [
                'title' => (string) __("cancellation.policies.{$policy->code->value}.title", [], $locale->value),
                'text' => (string) __("cancellation.policies.{$policy->code->value}.text", [], $locale->value),
            ];
        }

        return [
            'policy_id' => $policy->id,
            'code' => $policy->code->value,
            'timezone' => $unit->property->timezone,
            'texts' => $texts,
        ];
    }
}
