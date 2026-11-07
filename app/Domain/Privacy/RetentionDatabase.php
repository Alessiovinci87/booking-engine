<?php

namespace App\Domain\Privacy;

use App\Domain\Tenancy\Exceptions\InvalidTenantId;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Str;

/**
 * Accesso al database con i privilegi del ruolo app_retention (ADR-0009, ADR-0018): SELECT e DELETE solo
 * sulle tabelle soggette a conservazione o cancellate all'uscita del cliente; nessun INSERT o UPDATE;
 * soggetto a RLS come app_runtime. Usato solo dal job di conservazione (7.2) e dalla cancellazione dei
 * dati del tenant secondo il DPA (7.1).
 *
 * - modalità "connection" (produzione): connessione dedicata pgsql_retention;
 * - modalità "set_role" (test e sviluppo locale): SET LOCAL ROLE app_retention sulla connessione
 *   predefinita, poi RESET ROLE. app_runtime può farlo solo se lo script dei ruoli è stato eseguito con
 *   APP_RETENTION_SET_ROLE_FOR_TESTS=1, che in produzione non si imposta.
 *
 * Il contesto del tenant (app.tenant_id) vale solo dentro la callback: null per le righe di piattaforma.
 */
final class RetentionDatabase
{
    public function __construct(private readonly DatabaseManager $db) {}

    /**
     * @template TReturn
     *
     * @param  callable(ConnectionInterface): TReturn  $fn
     * @return TReturn
     */
    public function run(?string $tenantId, callable $fn): mixed
    {
        if ($tenantId !== null && ! Str::isUuid($tenantId)) {
            throw InvalidTenantId::make();
        }

        if (config('retention.db_mode') === 'set_role') {
            $connection = $this->db->connection();

            return $connection->transaction(function () use ($connection, $tenantId, $fn): mixed {
                $previous = $connection->selectOne("select current_setting('app.tenant_id', true) as value")->value ?? '';
                $connection->statement('SET LOCAL ROLE app_retention');
                $this->applyTenant($connection, $tenantId);

                $result = $fn($connection);

                // In una transazione annidata SET LOCAL resterebbe attivo fino alla fine di quella esterna.
                $connection->statement('RESET ROLE');
                $connection->select("select set_config('app.tenant_id', ?, true)", [(string) $previous]);

                return $result;
            });
        }

        $connection = $this->db->connection((string) config('retention.connection'));

        return $connection->transaction(function () use ($connection, $tenantId, $fn): mixed {
            $this->applyTenant($connection, $tenantId);

            return $fn($connection);
        });
    }

    private function applyTenant(ConnectionInterface $connection, ?string $tenantId): void
    {
        // Parametro legato; '' = nessun tenant (app_current_tenant() restituisce NULL).
        $connection->select("select set_config('app.tenant_id', ?, true)", [$tenantId === null ? '' : strtolower($tenantId)]);
    }
}
