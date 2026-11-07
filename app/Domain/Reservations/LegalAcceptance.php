<?php

namespace App\Domain\Reservations;

use App\Domain\Inventory\Models\Property;

/**
 * Versioni di condizioni e informativa privacy accettate alla prenotazione (07, accepted_*_version).
 * Il modello di 02 non ha versioni esplicite dei documenti: la versione è un'impronta del link che la
 * struttura pubblica, così l'ospite accetta esattamente il documento che ha visto e un cambio di link
 * fra il caricamento della pagina e l'invio si nota (ADR-0011). Le versioni accettate finiscono
 * nell'audit della prenotazione: non sono dati personali.
 */
final class LegalAcceptance
{
    public static function version(?string $url): ?string
    {
        return $url === null || trim($url) === '' ? null : substr(hash('sha256', trim($url)), 0, 12);
    }

    public static function termsVersion(Property $property): ?string
    {
        return self::version($property->terms_url);
    }

    public static function privacyVersion(Property $property): ?string
    {
        return self::version($property->privacy_policy_url);
    }
}
