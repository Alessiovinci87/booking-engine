<?php

namespace App\Console\Commands;

use App\Domain\Tenancy\EmbedOrigins;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Domini della vista embed di un tenant (6.10): elenco, aggiunta, rimozione. Solo origin https senza
 * percorso; ogni modifica va nell'audit del tenant.
 */
final class TenantEmbedOriginsCommand extends Command
{
    protected $signature = 'tenant:embed-origins
        {slug : Slug del tenant}
        {--add=* : Origin da aggiungere (https://dominio[:porta], senza percorso)}
        {--remove=* : Origin da togliere}
        {--list : Mostra l\'elenco attuale}';

    protected $description = 'Gestisce i domini da cui il tenant può incorporare la pagina ospite';

    public function handle(TenantContext $tenants, EmbedOrigins $origins): int
    {
        $tenantId = DB::selectOne('select app_tenant_by_slug(?) as id', [(string) $this->argument('slug')])->id;

        if ($tenantId === null) {
            $this->error(__('tenancy.embed.unknown_tenant'));

            return self::FAILURE;
        }

        $add = $this->origins('add');
        $remove = $this->origins('remove');

        if ($add === null || $remove === null) {
            return self::FAILURE;
        }

        if ($add === [] && $remove === [] && ! $this->option('list')) {
            $this->error(__('tenancy.embed.nothing_to_do'));

            return self::FAILURE;
        }

        try {
            $current = $tenants->run((string) $tenantId, fn (): array => $add === [] && $remove === [] ? $origins->list() : $origins->update($add, $remove));
        } catch (InvalidArgumentException) {
            $this->error(__('tenancy.embed.too_many', ['max' => EmbedOrigins::MAX]));

            return self::FAILURE;
        }

        if ($current === []) {
            $this->line(__('tenancy.embed.empty'));
        }

        foreach ($current as $origin) {
            $this->line($origin);
        }

        return self::SUCCESS;
    }

    /**
     * Origin normalizzati di un'opzione; null (con l'errore già stampato) se uno non è valido.
     *
     * @return list<string>|null
     */
    private function origins(string $option): ?array
    {
        $origins = [];

        foreach ((array) $this->option($option) as $value) {
            $origin = EmbedOrigins::normalize((string) $value);

            if ($origin === null) {
                $this->error(__('tenancy.embed.invalid', ['origin' => (string) $value]));

                return null;
            }

            $origins[] = $origin;
        }

        return $origins;
    }
}
