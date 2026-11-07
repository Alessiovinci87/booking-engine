<?php

namespace App\Domain\Tenancy;

use App\Domain\Tenancy\Audit\AuditActor;
use App\Domain\Tenancy\Audit\AuditLogger;
use App\Domain\Tenancy\Models\Tenant;

/**
 * Domini da cui il tenant incorpora la pagina ospite (vista embed: `frame-ancestors`, e Origin ammessi
 * dall'API pubblica; 07, 08, ADR-0010). Si gestiscono dalla piattaforma (6.10,
 * `php artisan tenant:embed-origins`). Esegue nel contesto del tenant.
 */
final class EmbedOrigins
{
    public const MAX = 20;

    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * Origin normalizzato (`https://host[:porta]`) oppure null: solo https, un nome di dominio con
     * almeno un punto (niente IP né localhost), nessun utente, percorso, query o frammento.
     */
    public static function normalize(string $value): ?string
    {
        $parts = parse_url(trim($value));

        if (! is_array($parts) || ($parts['scheme'] ?? null) === null || strtolower($parts['scheme']) !== 'https' || ! isset($parts['host'])) {
            return null;
        }

        if (isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment']) || str_contains(trim($value), '#')) {
            return null;
        }

        if (($parts['path'] ?? '') !== '' && $parts['path'] !== '/') {
            return null;
        }

        $host = strtolower($parts['host']);

        if (! str_contains($host, '.') || filter_var($host, FILTER_VALIDATE_IP) !== false || filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) === false) {
            return null;
        }

        $port = isset($parts['port']) && $parts['port'] !== 443 ? ':'.$parts['port'] : '';

        return 'https://'.$host.$port;
    }

    /** @return list<string> */
    public function list(): array
    {
        return Tenant::query()->firstOrFail()->embed_allowed_origins;
    }

    /**
     * Aggiunge e toglie origin già normalizzati. Restituisce l'elenco aggiornato; audit solo se cambia.
     *
     * @param  list<string>  $add
     * @param  list<string>  $remove
     * @return list<string>
     */
    public function update(array $add, array $remove): array
    {
        $tenant = Tenant::query()->lockForUpdate()->firstOrFail();
        $before = $tenant->embed_allowed_origins;
        $after = array_values(array_diff(array_values(array_unique([...$before, ...$add])), $remove));
        sort($after);

        if (count($after) > self::MAX) {
            throw new \InvalidArgumentException('too_many');
        }

        if ($after !== $before) {
            $tenant->embed_allowed_origins = $after;
            $tenant->save();
            $this->audit->record('tenant.embed_origins_updated', 'tenant', $tenant->id, ['embed_allowed_origins' => ['from' => $before, 'to' => $after]], actor: AuditActor::system());
        }

        return $after;
    }
}
