<?php

namespace App\Console\Commands;

use App\Domain\Tenancy\Auth\PasswordPolicy;
use App\Domain\Tenancy\Exceptions\TenantProvisioningFailed;
use App\Domain\Tenancy\TenantProvisioning;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;

/**
 * Comando interno per creare un tenant e il suo proprietario (01, 08). La password si chiede due
 * volte con input nascosto e non è mai un argomento (resterebbe nella cronologia della shell).
 */
final class CreateTenantCommand extends Command
{
    protected $signature = 'tenant:create
        {slug : Slug del tenant (minuscole, cifre e trattini)}
        {name : Nome del tenant}
        {owner-email : Email del proprietario}';

    protected $description = 'Crea un tenant e il suo utente proprietario (MFA obbligatoria al primo accesso)';

    public function handle(TenantProvisioning $provisioning): int
    {
        $slug = (string) $this->argument('slug');
        $name = trim((string) $this->argument('name'));
        $email = (string) $this->argument('owner-email');

        $input = Validator::make(
            ['name' => $name, 'email' => $email],
            ['name' => ['required', 'string', 'max:200'], 'email' => ['required', 'email:rfc', 'max:254']],
        );

        if ($input->fails()) {
            foreach ($input->errors()->all() as $message) {
                $this->error($message);
            }

            return self::FAILURE;
        }

        // Controlli prima della password, per non farla digitare inutilmente.
        $precheck = match (true) {
            ! TenantProvisioning::slugIsValid($slug) => TenantProvisioningFailed::invalidSlug(),
            $provisioning->slugIsTaken($slug) => TenantProvisioningFailed::slugTaken(),
            $provisioning->emailIsTaken($email) => TenantProvisioningFailed::emailTaken(),
            default => null,
        };

        if ($precheck !== null) {
            $this->error(__($precheck->reason()));

            return self::FAILURE;
        }

        $password = $this->askPassword();

        if ($password === null) {
            return self::FAILURE;
        }

        try {
            $result = $provisioning->create($slug, $name, $email, $password);
        } catch (TenantProvisioningFailed $e) {
            $this->error(__($e->reason()));

            return self::FAILURE;
        }

        $this->info(__('tenancy.create.done', ['slug' => $result['tenant']->slug]));
        $this->line(__('tenancy.create.mfa_notice'));

        return self::SUCCESS;
    }

    private function askPassword(): ?string
    {
        $password = (string) $this->secret(__('tenancy.create.password_prompt'));
        $confirmation = (string) $this->secret(__('tenancy.create.password_confirm_prompt'));

        if (! hash_equals($password, $confirmation)) {
            $this->error(__('tenancy.create.errors.password_mismatch'));

            return null;
        }

        $validator = Validator::make(['password' => $password], ['password' => PasswordPolicy::rules()]);

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $message) {
                $this->error($message);
            }

            return null;
        }

        return $password;
    }
}
