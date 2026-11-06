<?php

namespace App\Domain\Pricing\Enums;

/** Righe di prezzo (04, "Righe e totale"). */
enum PriceLineKind: string
{
    case NIGHT = 'NIGHT';
    case EXTRA_GUEST = 'EXTRA_GUEST';
    case CLEANING = 'CLEANING';
    case DISCOUNT = 'DISCOUNT';
}
