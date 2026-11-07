<?php

namespace App\Domain\Quotes;

/**
 * Link pubblico del preventivo (07 "/public/quotes/{token}", pagina ospite `/q/{token}`).
 *
 * Il token è un HMAC-SHA256 dell'ID del preventivo con una chiave derivata da APP_KEY, in base64url
 * (43 caratteri, 256 bit): il pannello può mostrare di nuovo lo stesso link ("Copia link", WhatsApp,
 * email) senza conservarlo in chiaro. Nel database resta solo `public_token_hash` (sha256 del token),
 * usato per risolvere il tenant (app_tenant_by_quote) e trovare il preventivo. Un link non si
 * invalida cambiando token ma con lo stato (REVOKED, EXPIRED, EXHAUSTED): ADR-0013.
 */
final class QuoteLinks
{
    public const TOKEN_PATTERN = '[A-Za-z0-9_-]{43}';

    public static function token(string $quoteId): string
    {
        $key = hash_hmac('sha256', 'quote-public-links', (string) config('app.key'), true);

        return rtrim(strtr(base64_encode(hash_hmac('sha256', $quoteId, $key, true)), '+/', '-_'), '=');
    }

    public static function hash(string $token): string
    {
        return hash('sha256', $token);
    }

    public static function url(string $quoteId): string
    {
        return rtrim((string) config('app.url'), '/').'/q/'.self::token($quoteId);
    }
}
