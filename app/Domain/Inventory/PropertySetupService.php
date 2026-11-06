<?php

namespace App\Domain\Inventory;

use App\Domain\Inventory\Enums\PropertyStatus;
use App\Domain\Inventory\Models\Property;
use App\Domain\Reservations\Enums\CancellationPolicyCode;
use App\Domain\Reservations\Models\CancellationPolicy;
use App\Domain\Tenancy\Audit\AuditLogger;
use Illuminate\Validation\ValidationException;

/**
 * Configurazione della struttura (01, 02, 07): creazione, modifica e pubblicazione. Lo stato della
 * struttura cambia solo da qui (11, attività 2.6; eccezione esplicita di Arch/StateTransitionsTest).
 *
 * Alla creazione si preparano le politiche di cancellazione standard della struttura (testi nei file
 * di lingua, ADR-0009); la predefinita si sceglie poi tra queste ed è obbligatoria per pubblicare.
 */
final class PropertySetupService
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @param  array<string, mixed>  $attributes  campi validati, senza stato né tenant
     * @param  array<string, array<string, string|null>|null>  $translations
     */
    public function create(array $attributes, array $translations = []): Property
    {
        $property = new Property;
        $property->fill($attributes)->save();

        foreach (CancellationPolicyCode::cases() as $code) {
            (new CancellationPolicy)->forceFill(['property_id' => $property->id, 'code' => $code])->save();
        }

        Translations::sync($property->translations(), $translations);
        $this->audit->recordModelChange('property.created', $property, ['locales' => array_keys(array_filter($translations))]);

        return $property->refresh();
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @param  array<string, array<string, string|null>|null>|null  $translations
     */
    public function update(Property $property, array $attributes, ?array $translations = null): Property
    {
        if ($property->status === PropertyStatus::PUBLISHED) {
            $this->assertStillPublishable($attributes, $translations);
        }

        $property->fill($attributes);
        $dirty = $property->isDirty();
        $property->save();

        if ($translations !== null && Translations::sync($property->translations(), $translations)) {
            if (! $dirty) {
                $property->bumpVersion();
            }

            $this->audit->record('property.translations_updated', 'property', $property->id, ['locales' => array_keys($translations)]);
        }

        if ($dirty) {
            $this->audit->recordModelChange('property.updated', $property);
        }

        return $property->refresh();
    }

    /**
     * Una struttura pubblicata non può perdere ciò che la pubblicazione richiede (07).
     *
     * @param  array<string, mixed>  $attributes
     * @param  array<string, array<string, string|null>|null>|null  $translations
     */
    private function assertStillPublishable(array $attributes, ?array $translations): void
    {
        $errors = [];

        if (array_key_exists('default_cancellation_policy_id', $attributes) && $attributes['default_cancellation_policy_id'] === null) {
            $errors['default_cancellation_policy_id'] = __('inventory.validation.required_when_published');
        }

        if ($translations !== null && array_key_exists('it', $translations) && $translations['it'] === null) {
            $errors['translations.it'] = __('inventory.validation.required_when_published');
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }
}
