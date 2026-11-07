<?php

namespace App\Domain\Quotes;

use App\Domain\Quotes\Models\Quote;

/**
 * Link pubblico del preventivo (07 "/public/quotes/{token}", pagina ospite `/q/{token}`).
 *
 * Come i feed iCal (ADR-0013 punti 24 e 36): token casuale di 32 byte in base64url (43 caratteri),
 * cifrato a riposo in `public_token_encrypted` per mostrare di nuovo lo stesso link nel pannello
 * ("Copia link", WhatsApp, email) e cercato per hash SHA-256 in `public_token_hash`, con cui
 * app_tenant_by_quote risolve il tenant. Il link non dipende da APP_KEY. Un link non si invalida
 * cambiando token ma con lo stato (REVOKED, EXPIRED, EXHAUSTED).
 */
final class QuoteLinks
{
    public const TOKEN_PATTERN = '[A-Za-z0-9_-]{43}';

    public static function newToken(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }

    public static function hash(string $token): string
    {
        return hash('sha256', $token);
    }

    public static function url(Quote $quote): string
    {
        return rtrim((string) config('app.url'), '/').'/q/'.$quote->public_token_encrypted;
    }
}
