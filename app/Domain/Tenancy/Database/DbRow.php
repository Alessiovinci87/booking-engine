<?php

namespace App\Domain\Tenancy\Database;

/**
 * Lettura tipizzata delle righe di DB::select (stdClass senza tipi): usata dalle query SQL dirette dei
 * dispatcher e dei cataloghi (Larastan livello 8, 7.4).
 */
final class DbRow
{
    public static function string(object $row, string $column): string
    {
        $value = get_object_vars($row)[$column] ?? null;

        return is_scalar($value) ? (string) $value : '';
    }

    public static function int(object $row, string $column): int
    {
        $value = get_object_vars($row)[$column] ?? null;

        return is_numeric($value) ? (int) $value : 0;
    }
}
