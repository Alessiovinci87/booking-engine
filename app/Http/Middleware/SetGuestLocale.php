<?php

namespace App\Http\Middleware;

use App\Domain\Tenancy\Enums\Locale;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Lingua dell'API pubblica (07): parametro locale, altrimenti header Accept-Language; it o en, con
 * fallback en. Imposta la lingua dell'applicazione, così anche i messaggi d'errore la seguono.
 */
final class SetGuestLocale
{
    public const ATTRIBUTE = 'guest_locale';

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $requested = $request->query('locale');
        $locale = is_string($requested) ? Locale::tryFrom($requested) : null;
        $locale ??= Locale::from($request->getPreferredLanguage([Locale::EN->value, Locale::IT->value]) ?? Locale::EN->value);

        app()->setLocale($locale->value);
        $request->attributes->set(self::ATTRIBUTE, $locale);

        return $next($request);
    }

    public static function of(Request $request): Locale
    {
        $locale = $request->attributes->get(self::ATTRIBUTE);

        return $locale instanceof Locale ? $locale : Locale::EN;
    }
}
