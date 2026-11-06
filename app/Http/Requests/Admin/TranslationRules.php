<?php

namespace App\Http\Requests\Admin;

use App\Domain\Tenancy\Enums\Locale;

/**
 * Regole per translations: { it: { name, description, house_rules } | null, en: … } (07).
 */
final class TranslationRules
{
    /**
     * @return array<string, list<string>>
     */
    public static function rules(): array
    {
        $rules = ['translations' => ['sometimes', 'array:it,en']];

        foreach (Locale::cases() as $locale) {
            $key = 'translations.'.$locale->value;
            $rules[$key] = ['nullable', 'array:name,description,house_rules'];
            $rules[$key.'.name'] = ['required_with:'.$key, 'string', 'max:200'];
            $rules[$key.'.description'] = ['nullable', 'string', 'max:5000'];
            $rules[$key.'.house_rules'] = ['nullable', 'string', 'max:5000'];
        }

        return $rules;
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array<string, array<string, string|null>|null>|null
     */
    public static function from(array $validated): ?array
    {
        if (! array_key_exists('translations', $validated) || ! is_array($validated['translations'])) {
            return null;
        }

        /** @var array<string, array<string, string|null>|null> $translations */
        $translations = $validated['translations'];

        return $translations;
    }
}
