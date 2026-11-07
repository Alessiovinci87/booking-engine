<?php

namespace App\Domain\Tenancy\Offboarding;

use App\Domain\Inventory\InventoryService;
use App\Domain\Privacy\RetentionDatabase;
use App\Domain\Tenancy\Auth\UserTenants;
use Illuminate\Contracts\Filesystem\Factory as FilesystemFactory;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;

/**
 * Cancellazione dei dati del tenant secondo il DPA (7.1; 09 "Uscita del cliente" punto 6, ADR-0018).
 *
 * - righe di tutte le tabelle con tenant_id, nell'ordine di PURGE_ORDER (figli prima dei genitori), con il
 *   ruolo app_retention (DELETE senza UPDATE né INSERT; i due cicli di FK sono DEFERRABLE);
 * - utenti che appartenevano solo a questo tenant (con i loro codici di recupero e link di reimpostazione);
 * - file: foto delle unità ed export completi.
 *
 * Restano: la riga `tenants` (nome, piano, DPA: dati della piattaforma per la fatturazione SaaS) e
 * `audit_log`, che non contiene valori personali e segue la conservazione della categoria F (7.2).
 * I backup scadono con la rotazione naturale (09).
 */
final class TenantDataPurger
{
    /**
     * Tabelle con tenant_id, figli prima dei genitori. Un test confronta l'elenco con il catalogo: una
     * tabella nuova deve essere aggiunta qui (o tra quelle conservate). Le occupazioni le cancella
     * InventoryService, unico scrittore della tabella (CLAUDE.md regola 13).
     */
    public const PURGE_ORDER = [
        'outbox_messages', 'sync_runs', 'inventory_conflicts', InventoryService::OCCUPANCY_TABLE, 'ics_feeds',
        'external_calendar_events', 'external_calendars', 'reservation_price_lines', 'reservation_changes',
        'payment_events', 'refunds', 'payments', 'quotes', 'reservations', 'guests', 'unit_photos',
        'unit_translations', 'season_rules', 'rate_plans', 'units', 'property_translations', 'properties',
        'cancellation_policies', 'stripe_accounts', 'tenant_exports', 'tenant_members',
    ];

    /** Tabelle con tenant_id che restano dopo la cancellazione. */
    public const KEPT = ['audit_log'];

    /** Vincoli DEFERRABLE dei due cicli di FK (migration 2027_04_01_000100). */
    private const DEFERRED = [
        'quotes_active_reservation_id_fk', 'reservations_quote_id_fk',
        'properties_default_cancellation_policy_id_fk', 'cancellation_policies_property_id_fk',
    ];

    public function __construct(
        private readonly RetentionDatabase $retention,
        private readonly InventoryService $inventory,
        private readonly UserTenants $userTenants,
        private readonly FilesystemFactory $files,
    ) {}

    /**
     * File e utenti da togliere, letti nel contesto del tenant prima della cancellazione.
     *
     * @return array{photos: array<string, list<string>>, exports: list<string>, users: list<string>}
     */
    public function plan(string $tenantId): array
    {
        $photos = [];

        foreach (DB::table('unit_photos')->get(['disk', 'path', 'thumbnail_path']) as $row) {
            foreach ([$row->path ?? null, $row->thumbnail_path ?? null] as $path) {
                if (is_string($path) && $path !== '') {
                    $photos[(string) $row->disk][] = $path;
                }
            }
        }

        $exports = DB::table('tenant_exports')->whereNotNull('file_path')->pluck('file_path')->map(fn ($p): string => (string) $p)->all();

        $users = [];

        foreach (DB::table('tenant_members')->pluck('user_id') as $userId) {
            if ($this->userTenants->of((string) $userId) === [$tenantId]) {
                $users[] = (string) $userId;
            }
        }

        return ['photos' => $photos, 'exports' => array_values($exports), 'users' => $users];
    }

    /**
     * @param  array{photos: array<string, list<string>>, exports: list<string>, users: list<string>}  $plan
     * @return array<string, int> righe cancellate per tabella
     */
    public function purge(string $tenantId, array $plan): array
    {
        $counts = $this->retention->run($tenantId, function (ConnectionInterface $db) use ($tenantId, $plan): array {
            $db->statement('SET CONSTRAINTS '.implode(', ', self::DEFERRED).' DEFERRED');
            $counts = [];

            foreach (self::PURGE_ORDER as $table) {
                $counts[$table] = $table === InventoryService::OCCUPANCY_TABLE
                    ? $this->inventory->purgeTenant($db, $tenantId)
                    : $db->table($table)->where('tenant_id', $tenantId)->delete();
            }

            $counts['users'] = $plan['users'] === [] ? 0 : $db->table('users')->whereIn('id', $plan['users'])->delete();

            return $counts;
        });

        $files = count($plan['exports']);

        foreach ($plan['photos'] as $disk => $paths) {
            $this->files->disk($disk)->delete($paths);
            $files += count($paths);
        }

        $this->files->disk((string) config('offboarding.disk'))->delete($plan['exports']);
        $counts['files'] = $files;

        return $counts;
    }
}
