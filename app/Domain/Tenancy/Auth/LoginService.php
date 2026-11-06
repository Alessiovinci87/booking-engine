<?php

namespace App\Domain\Tenancy\Auth;

use App\Domain\Tenancy\Audit\AuditActor;
use App\Domain\Tenancy\Audit\AuditLogger;
use App\Domain\Tenancy\Models\User;
use Illuminate\Contracts\Hashing\Hasher;

/**
 * Verifica delle credenziali del pannello (08) con limite ai tentativi (07) e audit di accessi e
 * fallimenti (08 "Audit log"). Non apre la sessione: lo fa il controller, dopo il risultato.
 *
 * Con email sconosciuta si calcola comunque un hash, per non rivelare l'esistenza dell'account con i
 * tempi di risposta.
 */
final class LoginService
{
    public function __construct(
        private readonly Hasher $hasher,
        private readonly LoginThrottle $throttle,
        private readonly AuditLogger $audit,
        private readonly UserTenants $userTenants,
    ) {}

    public function attempt(string $email, string $password, ?string $ip): LoginResult
    {
        if ($this->throttle->tooManyLoginAttempts($email, $ip)) {
            $user = $this->findUser($email);
            $this->audit->record(
                'auth.login.throttled',
                $user ? 'user' : null,
                $user?->id,
                tenantId: $user ? $this->userTenants->primaryOf($user->id) : null,
                actor: AuditActor::anonymous(),
            );

            return LoginResult::throttled($this->throttle->loginRetryAfter($email, $ip));
        }

        $user = $this->findUser($email);

        if ($user === null) {
            $this->hasher->check($password, $this->dummyHash());
            $this->throttle->hitLogin($email, $ip);
            $this->audit->record('auth.login.failed', actor: AuditActor::anonymous());

            return LoginResult::invalid();
        }

        if (! $this->hasher->check($password, $user->password_hash)) {
            $this->throttle->hitLogin($email, $ip);
            $this->audit->record(
                'auth.login.failed',
                'user',
                $user->id,
                tenantId: $this->userTenants->primaryOf($user->id),
                actor: AuditActor::anonymous(),
            );

            return LoginResult::invalid();
        }

        if ($this->hasher->needsRehash($user->password_hash)) {
            $user->forceFill(['password_hash' => $password])->save();
        }

        $this->throttle->clearAccount($email);
        $this->audit->record(
            'auth.login.succeeded',
            'user',
            $user->id,
            ['mfa_enabled' => $user->hasMfaEnabled()],
            tenantId: $this->userTenants->primaryOf($user->id),
            actor: AuditActor::user($user->id),
        );

        return LoginResult::success($user);
    }

    private function findUser(string $email): ?User
    {
        return User::query()->whereRaw('lower(email) = ?', [User::normalizeEmail($email)])->first();
    }

    private function dummyHash(): string
    {
        static $hash = null;

        return $hash ??= $this->hasher->make('password-inesistente-per-tempi-costanti');
    }
}
