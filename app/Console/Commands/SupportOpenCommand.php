<?php

namespace App\Console\Commands;

use App\Domain\Tenancy\Exceptions\BusinessRuleViolation;
use App\Domain\Tenancy\Support\SupportAccessService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Apertura di un accesso di supporto in sola lettura (7.5; 08; procedura in docs/guide/accesso-supporto.md):
 * autorizzazione a tempo (massimo 2 ore) per un operatore di piattaforma con MFA attiva, motivazione
 * obbligatoria senza dati personali. Visibile al gestore nella pagina "Accessi di supporto".
 */
final class SupportOpenCommand extends Command
{
    protected $signature = 'support:open
        {tenant : Slug del tenant}
        {--operator= : Email dell\'operatore di piattaforma}
        {--reason= : Motivazione (10-200 caratteri, senza dati personali)}
        {--hours=1 : Durata in ore (massimo 2)}';

    protected $description = 'Apre un accesso di supporto in sola lettura al pannello di un tenant';

    public function handle(SupportAccessService $support): int
    {
        $tenantId = DB::selectOne('select app_tenant_by_slug(?) as id', [(string) $this->argument('tenant')])?->id;

        if (! is_string($tenantId)) {
            $this->error(__('tenancy.support.unknown_tenant'));

            return self::FAILURE;
        }

        $operator = $support->findOperator((string) $this->option('operator'));

        if ($operator === null) {
            $this->error(__('tenancy.support.unknown_operator'));

            return self::FAILURE;
        }

        $hours = filter_var($this->option('hours'), FILTER_VALIDATE_INT);

        try {
            $grant = $support->open($tenantId, $operator, (string) $this->option('reason'), $hours === false ? 0 : $hours);
        } catch (BusinessRuleViolation $e) {
            $this->error(__('tenancy.support.refused', ['code' => $e->errorCode]));

            return self::FAILURE;
        }

        $this->info(__('tenancy.support.opened', ['id' => $grant->id, 'expires' => $grant->expires_at->toIso8601ZuluString()]));

        return self::SUCCESS;
    }
}
