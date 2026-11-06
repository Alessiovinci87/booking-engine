<?php

namespace App\Domain\Tenancy\Auth;

use Illuminate\Database\DatabaseManager;

/**
 * Tenant di un utente tramite la funzione SECURITY DEFINER app_user_tenants (08): restituisce solo gli
 * ID, senza aprire ad app_runtime la lettura di tenant_members fuori dal contesto.
 */
final class UserTenants
{
    public function __construct(private readonly DatabaseManager $db) {}

    /** @return list<string> */
    public function of(string $userId): array
    {
        $rows = $this->db->connection()->select('select app_user_tenants(?) as tenant_id', [$userId]);

        return array_values(array_filter(array_map(
            static fn (object $row): ?string => isset($row->tenant_id) && is_string($row->tenant_id) ? $row->tenant_id : null,
            $rows,
        )));
    }

    /** Nella 1.0 un utente appartiene a un solo tenant (07): il primo per data di adesione. */
    public function primaryOf(string $userId): ?string
    {
        return $this->of($userId)[0] ?? null;
    }
}
