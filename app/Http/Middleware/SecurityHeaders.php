<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Header di sicurezza (08 "Header e frontend"; ADR-0009):
 *
 * - CSP restrittiva con `default-src 'self'`; gli script ammessi sono quelli dell'applicazione con un
 *   nonce per richiesta (anche quelli che `@vite` genera);
 * - `frame-ancestors 'none'` per pannello, pagina ospite e API; la vista embed (`embed`) imposta le
 *   origini registrate dal gestore con `frame-ancestors` (attributo `embed_frame_ancestors`);
 * - `X-Content-Type-Options: nosniff` e `Referrer-Policy` ovunque.
 *
 * Eccezione locale: con il server di sviluppo di Vite attivo (file public/hot) la sua origine entra in
 * script, stili e connessioni (anche websocket per l'aggiornamento a caldo) e gli stili inline sono
 * ammessi, perché Vite inietta il CSS con tag <style> in sviluppo. In produzione il file non c'è.
 */
final class SecurityHeaders
{
    public const EMBED_ANCESTORS_ATTRIBUTE = 'embed_frame_ancestors';

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next, string $surface = 'page'): Response
    {
        $nonce = Str::random(32);

        if ($surface !== 'api') {
            Vite::useCspNonce($nonce);
        }

        $response = $next($request);

        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->headers->set('Content-Security-Policy', $surface === 'api'
            ? "default-src 'none'; frame-ancestors 'none'"
            : self::policy($nonce, $surface === 'embed' ? self::embedAncestors($request) : "'none'"));

        return $response;
    }

    public static function policy(string $nonce, string $frameAncestors): string
    {
        $hot = self::viteDevOrigin();
        $extraImages = implode(' ', array_filter((array) config('security.csp_img_src', []), 'is_string'));

        $directives = [
            'default-src' => "'self'",
            'script-src' => trim("'self' 'nonce-{$nonce}' ".($hot ?? '')),
            'style-src' => trim("'self' ".($hot !== null ? "{$hot} 'unsafe-inline'" : '')),
            'img-src' => trim("'self' data: blob: {$extraImages}"),
            'font-src' => "'self' data:",
            'connect-src' => trim("'self' ".($hot !== null ? $hot.' '.preg_replace('/^http/', 'ws', $hot) : '')),
            'object-src' => "'none'",
            'base-uri' => "'self'",
            'form-action' => "'self'",
            'frame-ancestors' => $frameAncestors,
        ];

        return implode('; ', array_map(fn (string $k, string $v): string => "{$k} {$v}", array_keys($directives), $directives));
    }

    /** Origine del server di sviluppo di Vite, solo in locale e solo se è attivo. */
    private static function viteDevOrigin(): ?string
    {
        if (! app()->environment('local') || ! File::exists(public_path('hot'))) {
            return null;
        }

        $url = trim((string) File::get(public_path('hot')));
        $origin = CheckPublicOrigin::normalize($url);

        return $origin;
    }

    private static function embedAncestors(Request $request): string
    {
        $origins = $request->attributes->get(self::EMBED_ANCESTORS_ATTRIBUTE);

        return is_array($origins) && $origins !== [] ? implode(' ', array_filter($origins, 'is_string')) : "'none'";
    }
}
