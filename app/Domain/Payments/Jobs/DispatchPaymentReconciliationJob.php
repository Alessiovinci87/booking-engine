<?php

namespace App\Domain\Payments\Jobs;

use App\Domain\Communications\Alerts\AlertSignal;
use App\Domain\Communications\Alerts\OperationalAlerts;
use App\Domain\Inventory\InventoryService;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Dispatcher della riconciliazione dei pagamenti (05 "Riconciliazione"): ogni 15 minuti il giro
 * completo, ogni 5 minuti solo CAPTURING (routes/console.php). Con app_admin_ops legge solo gli ID dei
 * tenant che hanno qualcosa da riconciliare e accoda un ReconcilePaymentsJob per tenant, che lavora
 * con app_runtime e contesto tenant (08 "Job cross-tenant").
 *
 * Il giro completo controlla anche I15 su tutti i tenant: ogni violazione è un'allerta P1.
 */
final class DispatchPaymentReconciliationJob implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly string $scope = ReconcilePaymentsJob::FULL) {}

    public function handle(DatabaseManager $db): void
    {
        $connection = $db->connection((string) config('payments.release_dispatcher_connection'));
        $now = CarbonImmutable::now();

        $conditions = ["(p.status = 'CAPTURE_PENDING')"];
        $bindings = [];

        if ($this->scope === ReconcilePaymentsJob::FULL) {
            $conditions[] = "(p.status = 'PENDING' and p.purpose <> 'OFFLINE' and p.checkout_session_id is null and p.created_at < ?)";
            $conditions[] = "(p.status = 'AUTHORIZED')";
            $bindings[] = $now->subMinutes(ReconcilePaymentsJob::PENDING_WITHOUT_SESSION_MINUTES)->toIso8601ZuluString('microsecond');
        }

        $tenants = array_map(fn (object $row): string => (string) $row->tenant_id, $connection->select(
            'select distinct p.tenant_id::text as tenant_id from payments p where '.implode(' or ', $conditions).' limit 500',
            $bindings,
        ));

        if ($this->scope === ReconcilePaymentsJob::FULL) {
            $refundTenants = $connection->select(
                "select distinct tenant_id::text as tenant_id from refunds
                 where (status = 'PENDING' and updated_at < ?) or (status = 'FAILED' and next_attempt_at <= ?) limit 500",
                [$now->subMinutes(ReconcilePaymentsJob::PENDING_REFUND_MINUTES)->toIso8601ZuluString('microsecond'), $now->toIso8601ZuluString('microsecond')],
            );
            $tenants = array_values(array_unique([...$tenants, ...array_map(fn (object $row): string => (string) $row->tenant_id, $refundTenants)]));

            foreach (InventoryService::i15Violations($connection) as $violation) {
                // Incoerenza stato/occupazione (08: incidente P1): log critical ed email alla piattaforma.
                app(OperationalAlerts::class)->raise(AlertSignal::I15_VIOLATION, $violation);
            }
        }

        foreach ($tenants as $tenantId) {
            ReconcilePaymentsJob::dispatch($tenantId, $this->scope);
        }
    }
}
