<?php

namespace App\Domain\Inventory;

use App\Domain\Tenancy\Enums\Locale;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Traduzioni IT/EN di strutture e unità (02, 07): nell'API viaggiano come
 * translations: { it: { name, description, house_rules }, en: { … } }. Una lingua a null si elimina,
 * una lingua assente resta com'è. Nessuna traduzione automatica.
 */
final class Translations
{
    public const FIELDS = ['name', 'description', 'house_rules'];

    /**
     * @param  HasMany<covariant Model, covariant Model>  $relation
     * @param  array<string, array<string, string|null>|null>  $input
     * @return bool true se qualcosa è cambiato
     */
    public static function sync(HasMany $relation, array $input): bool
    {
        $changed = false;

        foreach (Locale::cases() as $locale) {
            if (! array_key_exists($locale->value, $input)) {
                continue;
            }

            $data = $input[$locale->value];

            if ($data === null) {
                $changed = $relation->getQuery()->clone()->where('locale', $locale->value)->delete() > 0 || $changed;

                continue;
            }

            $values = [];

            foreach (self::FIELDS as $field) {
                $values[$field] = $data[$field] ?? null;
            }

            $translation = $relation->getQuery()->clone()->where('locale', $locale->value)->first()
                ?? $relation->make(['locale' => $locale->value]);
            $translation->fill($values);

            if (! $translation->exists || $translation->isDirty()) {
                $translation->save();
                $changed = true;
            }
        }

        return $changed;
    }

    /**
     * Mappa { it: {…}|null, en: {…}|null } per le risposte dell'API.
     *
     * @param  Collection<int, covariant Model>  $translations
     * @return array<string, array<string, mixed>|null>
     */
    public static function toArray(Collection $translations): array
    {
        $out = [];

        foreach (Locale::cases() as $locale) {
            $row = $translations->first(fn (Model $t): bool => self::localeOf($t) === $locale->value);
            $out[$locale->value] = $row === null ? null : $row->only(self::FIELDS);
        }

        return $out;
    }

    private static function localeOf(Model $translation): string
    {
        $locale = $translation->getAttribute('locale');

        return $locale instanceof Locale ? $locale->value : (string) $locale;
    }
}
