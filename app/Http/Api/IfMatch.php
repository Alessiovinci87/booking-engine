<?php

namespace App\Http\Api;

use App\Domain\Tenancy\Exceptions\BusinessRuleViolation;
use Illuminate\Http\Request;

/**
 * Concorrenza ottimistica (07): la PATCH porta If-Match: "<version>". Header assente, non valido o con
 * una versione diversa da quella corrente → 412 VERSION_MISMATCH con la versione corrente nei dettagli.
 * Il modello va letto con lockForUpdate prima del confronto, così il controllo vale fino al commit.
 */
final class IfMatch
{
    public static function assert(Request $request, int $currentVersion): void
    {
        $header = trim((string) $request->header('If-Match', ''));

        if (preg_match('/^(?:W\/)?"?(\d{1,9})"?$/', $header, $m) !== 1 || (int) $m[1] !== $currentVersion) {
            throw BusinessRuleViolation::make(412, 'VERSION_MISMATCH', ['current_version' => $currentVersion]);
        }
    }

    public static function etag(int $version): string
    {
        return '"'.$version.'"';
    }
}
