<?php

namespace App\Domain\Inventory;

use App\Domain\Inventory\Enums\PropertyStatus;
use App\Domain\Inventory\Enums\UnitStatus;
use App\Domain\Inventory\Models\Property;
use App\Domain\Inventory\Models\PropertyTranslation;
use App\Domain\Inventory\Models\Unit;
use App\Domain\Payments\Models\StripeAccount;
use App\Domain\Reservations\Enums\CancellationPolicyCode;
use App\Domain\Reservations\Models\CancellationPolicy;
use App\Domain\Tenancy\Audit\AuditLogger;
use App\Domain\Tenancy\Enums\Locale;
use App\Domain\Tenancy\Exceptions\BusinessRuleViolation;
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
    public const GUARD_ACTIVE_UNITS = 'NO_ACTIVE_UNITS';

    public const GUARD_TRANSLATION_IT = 'TRANSLATION_IT_MISSING';

    public const GUARD_CANCELLATION_POLICY = 'CANCELLATION_POLICY_MISSING';

    public const GUARD_PAYMENTS = 'PAYMENTS_NOT_ENABLED';

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
     * Pubblica la struttura (07, POST /properties/{id}/publish). Guardie, nell'ordine di 07:
     * almeno un'unità attiva, traduzione it, politica di cancellazione predefinita (ADR-0009), Stripe
     * charges_enabled. Se ne manca qualcuna risponde 422 con il codice della prima e l'elenco di tutte
     * in details.missing. Pubblicare una struttura già pubblicata non cambia nulla.
     */
    public function publish(Property $property): Property
    {
        if ($property->status === PropertyStatus::PUBLISHED) {
            return $property;
        }

        if ($property->status !== PropertyStatus::DRAFT) {
            throw BusinessRuleViolation::make(409, 'ILLEGAL_TRANSITION', ['from' => $property->status->value, 'to' => PropertyStatus::PUBLISHED->value]);
        }

        $missing = $this->missingForPublication($property);

        if ($missing !== []) {
            throw BusinessRuleViolation::make(422, $missing[0], ['missing' => $missing]);
        }

        $property->status = PropertyStatus::PUBLISHED;
        $property->save();
        $this->audit->record('property.published', 'property', $property->id, ['from' => PropertyStatus::DRAFT->value, 'to' => PropertyStatus::PUBLISHED->value]);

        return $property->refresh();
    }

    /**
     * Codici delle guardie di pubblicazione non soddisfatte, nell'ordine di 07 ([] = pubblicabile).
     *
     * @return list<string>
     */
    public function missingForPublication(Property $property): array
    {
        $missing = [];

        if (! Unit::query()->where('property_id', $property->id)->where('status', UnitStatus::ACTIVE->value)->exists()) {
            $missing[] = self::GUARD_ACTIVE_UNITS;
        }

        if (! PropertyTranslation::query()->where('property_id', $property->id)->where('locale', Locale::IT->value)->exists()) {
            $missing[] = self::GUARD_TRANSLATION_IT;
        }

        if ($property->default_cancellation_policy_id === null) {
            $missing[] = self::GUARD_CANCELLATION_POLICY;
        }

        // 07 ammette in alternativa "solo unità con prenotazione manuale", ma il modello dati (02) non
        // ha un'unità solo manuale: serve l'account Stripe abilitato agli incassi (rapporto di M2).
        if (! StripeAccount::query()->where('charges_enabled', true)->exists()) {
            $missing[] = self::GUARD_PAYMENTS;
        }

        return $missing;
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
