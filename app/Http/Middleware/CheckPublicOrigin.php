<?php

namespace App\Http\Middleware;

use App\Domain\Tenancy\Exceptions\BusinessRuleViolation;
use App\Domain\Tenancy\Http\WithTenantContext;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Controllo dell'header Origin sull'API pubblica (07, ADR-0010): l'API non usa cookie né CSRF, quindi
 * una chiamata da un'altra origine passa solo se l'origine è quella dell'applicazione (pagina ospite e
 * vista embed, che è servita da qui) o è tra gli embed_allowed_origins del tenant. Senza header Origin
 * (navigazione diretta, client non browser) non c'è nulla da verificare: restano rate limit e
 * Idempotency-Key. Origine non ammessa → 403 FORBIDDEN.
 *
 * Va dopo il middleware tenant: legge il tenant dal contesto o dall'attributo della modalità `steps`.
 */
final class CheckPublicOrigin
{
    public function __construct(private readonly TenantContext $tenants) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $origin = $request->headers->get('Origin');

        if ($origin === null || $origin === '' || self::normalize($origin) === self::appOrigin()) {
            return $next($request);
        }

        $tenantId = $this->tenants->currentTenantId() ?? $request->attributes->get(WithTenantContext::TENANT_ATTRIBUTE);

        if (! is_string($tenantId) || ! in_array(self::normalize($origin), self::allowedFor($this->tenants, $tenantId), true)) {
            throw BusinessRuleViolation::make(403, 'FORBIDDEN');
        }

        return $next($request);
    }

    /**
     * Origini registrate dal gestore, normalizzate (schema://host[:porta]).
     *
     * @return list<string>
     */
    public static function allowedFor(TenantContext $tenants, string $tenantId): array
    {
        $origins = $tenants->run($tenantId, fn (): array => Tenant::query()->whereKey($tenantId)->value('embed_allowed_origins') ?? []);

        return array_values(array_filter(array_map(fn (mixed $o): ?string => is_string($o) ? self::normalize($o) : null, $origins)));
    }

    public static function appOrigin(): string
    {
        return self::normalize((string) config('app.url')) ?? '';
    }

    public static function normalize(string $url): ?string
    {
        $parts = parse_url(trim($url));

        if (! is_array($parts) || ! isset($parts['scheme'], $parts['host'])) {
            return null;
        }

        $scheme = strtolower($parts['scheme']);
        $port = isset($parts['port']) && ! (($scheme === 'https' && $parts['port'] === 443) || ($scheme === 'http' && $parts['port'] === 80))
            ? ':'.$parts['port'] : '';

        return $scheme.'://'.strtolower($parts['host']).$port;
    }
}
