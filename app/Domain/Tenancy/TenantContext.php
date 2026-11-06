<?php

namespace App\Domain\Tenancy;

use App\Domain\Tenancy\Exceptions\InvalidTenantId;
use App\Domain\Tenancy\Exceptions\TenantContextConflict;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Str;

/**
 * Unico punto in cui si imposta il tenant corrente nel database (08, "Livello database (RLS)").
 *
 * run() apre una transazione sulla connessione di runtime ed esegue
 * set_config('app.tenant_id', :tenant, true), equivalente a SET LOCAL: il valore vale solo fino alla
 * fine della transazione e non resta mai su connessioni riutilizzate. Le policy RLS leggono il valore
 * tramite la funzione SQL app_current_tenant().
 */
final class TenantContext
{
    private ?string $currentTenantId = null;

    public function __construct(private readonly DatabaseManager $db) {}

    /**
     * Esegue $fn in una transazione con il contesto del tenant indicato.
     *
     * Un run() annidato con lo stesso tenant riusa la transazione esterna; con un tenant diverso
     * solleva TenantContextConflict (mai due tenant nella stessa unità di lavoro).
     *
     * @template TReturn
     *
     * @param  callable(): TReturn  $fn
     * @return TReturn
     */
    public function run(string $tenantId, callable $fn): mixed
    {
        if (! Str::isUuid($tenantId)) {
            throw InvalidTenantId::make();
        }

        $tenantId = strtolower($tenantId);

        if ($this->currentTenantId !== null) {
            if ($this->currentTenantId !== $tenantId) {
                throw TenantContextConflict::make();
            }

            return $fn();
        }

        $connection = $this->db->connection();

        return $connection->transaction(function () use ($connection, $tenantId, $fn): mixed {
            $previous = $this->setting($connection);
            $this->apply($connection, $tenantId);
            $this->currentTenantId = $tenantId;

            try {
                $result = $fn();
            } finally {
                $this->currentTenantId = null;
            }

            // In una transazione annidata (savepoint) SET LOCAL resterebbe attivo fino alla fine della
            // transazione esterna: si ripristina il valore precedente. In caso di eccezione il rollback
            // del savepoint annulla già l'impostazione.
            if ($connection->transactionLevel() > 1) {
                $this->apply($connection, $previous ?? '');
            }

            return $result;
        });
    }

    /** Tenant del run() in corso, oppure null fuori da un contesto tenant. */
    public function currentTenantId(): ?string
    {
        return $this->currentTenantId;
    }

    public function hasTenant(): bool
    {
        return $this->currentTenantId !== null;
    }

    private function apply(ConnectionInterface $connection, string $value): void
    {
        // Parametro legato, mai interpolazione; is_local = true equivale a SET LOCAL.
        $connection->select("select set_config('app.tenant_id', ?, true)", [$value]);
    }

    private function setting(ConnectionInterface $connection): ?string
    {
        $row = $connection->selectOne("select current_setting('app.tenant_id', true) as value");

        return is_object($row) && isset($row->value) && is_string($row->value) ? $row->value : null;
    }
}
