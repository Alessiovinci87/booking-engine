<?php

namespace App\Domain\Communications;

/**
 * Indirizzi email mascherati per i log (08: "email mascherate"): resta la prima lettera e il dominio.
 */
final class RecipientMask
{
    public static function email(string $address): string
    {
        $at = strrpos($address, '@');

        if ($at === false || $at === 0) {
            return '***';
        }

        return mb_substr($address, 0, 1).'***'.substr($address, $at);
    }
}
