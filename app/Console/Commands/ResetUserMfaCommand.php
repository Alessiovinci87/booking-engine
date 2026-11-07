<?php

namespace App\Console\Commands;

use App\Domain\Tenancy\Auth\MfaReset;
use App\Domain\Tenancy\Models\User;
use Illuminate\Console\Command;

/**
 * Azzeramento della MFA di un gestore che ha perso app e codici di recupero (6.10, procedura in
 * docs/guide/azzeramento-mfa.md). Chiede conferma esplicita dopo il promemoria sulla verifica
 * dell'identità; l'email non finisce nell'audit né nell'output oltre al promemoria iniziale.
 */
final class ResetUserMfaCommand extends Command
{
    protected $signature = 'user:reset-mfa
        {email : Email dell\'utente del pannello}
        {--reason= : Motivazione per l\'audit (10-200 caratteri, senza dati personali)}';

    protected $description = 'Azzera MFA e codici di recupero di un utente e chiude le sue sessioni';

    public function handle(MfaReset $reset): int
    {
        $this->warn(__('tenancy.mfa_reset.reminder'));

        $reason = trim((string) $this->option('reason'));

        if (! MfaReset::reasonIsValid($reason)) {
            $this->error(__('tenancy.mfa_reset.invalid_reason'));

            return self::FAILURE;
        }

        $user = User::query()->whereRaw('lower(email) = ?', [mb_strtolower(trim((string) $this->argument('email')))])->first();

        if ($user === null) {
            $this->error(__('tenancy.mfa_reset.unknown_user'));

            return self::FAILURE;
        }

        if (! $this->confirm(__('tenancy.mfa_reset.confirm'))) {
            $this->line(__('tenancy.mfa_reset.aborted'));

            return self::FAILURE;
        }

        $reset->reset($user, $reason);
        $this->info(__('tenancy.mfa_reset.done', ['id' => $user->id]));

        return self::SUCCESS;
    }
}
