<?php

namespace App\Domain\Tenancy\Enums;

/** Lingue supportate: ospite it/en, pannello it (CLAUDE.md regola 12). */
enum Locale: string
{
    case IT = 'it';
    case EN = 'en';
}
