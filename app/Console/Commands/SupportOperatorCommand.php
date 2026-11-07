<?php

namespace App\Console\Commands;

use App\Domain\Tenancy\Exceptions\BusinessRuleViolation;
use App\Domain\Tenancy\Support\SupportAccessService;
use Illuminate\Console\Command;

/**
 * Operatori di piattaforma (7.5; 08): segna o toglie il flag `is_platform_operator` su un utente
 * esistente senza membership. La MFA resta obbligatoria: senza MFA attiva support:open rifiuta.
 */
final class SupportOperatorCommand extends Command
{
    protected $signature = 'support:operator
        {email : Email dell\'utente}
        {--remove : Toglie il flag invece di impostarlo}';

    protected $description = 'Segna (o toglie) un utente come operatore di piattaforma per l\'accesso di supporto';

    public function handle(SupportAccessService $support): int
    {
        $user = $support->findOperator((string) $this->argument('email'));

        if ($user === null) {
            $this->error(__('tenancy.support.unknown_operator'));

            return self::FAILURE;
        }

        $remove = (bool) $this->option('remove');

        try {
            $support->setOperator($user, ! $remove);
        } catch (BusinessRuleViolation $e) {
            $this->error(__('tenancy.support.refused', ['code' => $e->errorCode]));

            return self::FAILURE;
        }

        $this->info(__($remove ? 'tenancy.support.operator_removed' : 'tenancy.support.operator_added', ['id' => $user->id]));

        if (! $remove && ! $user->hasMfaEnabled()) {
            $this->warn(__('tenancy.support.mfa_notice'));
        }

        return self::SUCCESS;
    }
}
