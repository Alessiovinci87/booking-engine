<?php

namespace App\Domain\Tenancy\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * Colonne text[] di PostgreSQL (es. tenants.embed_allowed_origins) come lista di stringhe.
 *
 * @implements CastsAttributes<list<string>, mixed>
 */
final class PostgresTextArray implements CastsAttributes
{
    /** @return list<string> */
    public function get(Model $model, string $key, mixed $value, array $attributes): array
    {
        if ($value === null || $value === '{}' || $value === '') {
            return [];
        }

        if (! is_string($value) || ! str_starts_with($value, '{') || ! str_ends_with($value, '}')) {
            throw new InvalidArgumentException('Array PostgreSQL non valido.');
        }

        $items = str_getcsv(substr($value, 1, -1), ',', '"', '\\');

        return array_map(static fn (?string $item): string => (string) $item, $items);
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): string
    {
        if (! is_array($value)) {
            throw new InvalidArgumentException('Assegnare una lista di stringhe.');
        }

        $escaped = array_map(static function (mixed $item): string {
            if (! is_string($item)) {
                throw new InvalidArgumentException('Assegnare una lista di stringhe.');
            }

            return '"'.str_replace(['\\', '"'], ['\\\\', '\\"'], $item).'"';
        }, array_values($value));

        return '{'.implode(',', $escaped).'}';
    }
}
