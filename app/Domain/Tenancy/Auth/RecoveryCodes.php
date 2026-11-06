<?php

namespace App\Domain\Tenancy\Auth;

use App\Domain\Tenancy\Models\User;
use App\Domain\Tenancy\Models\UserRecoveryCode;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Codici di recupero MFA monouso (08), salvati solo come hash HMAC-SHA256 con la chiave
 * dell'applicazione: i codici hanno 50 bit casuali, quindi l'hash veloce non è un punto debole e
 * permette la ricerca diretta.
 */
final class RecoveryCodes
{
    private const ALPHABET = 'abcdefghjkmnpqrstuvwxyz23456789';

    /**
     * Sostituisce i codici dell'utente con una nuova serie e la restituisce in chiaro, una sola volta.
     *
     * @return list<string>
     */
    public function regenerate(User $user): array
    {
        $count = (int) config('security.mfa.recovery_codes');
        $codes = [];

        while (count($codes) < $count) {
            $code = $this->randomCode();
            $codes[$code] = true;
        }

        $codes = array_keys($codes);

        DB::transaction(function () use ($user, $codes): void {
            $user->recoveryCodes()->delete();

            foreach ($codes as $code) {
                $user->recoveryCodes()->create(['code_hash' => self::hash($code)]);
            }
        });

        return $codes;
    }

    /**
     * Consuma un codice valido e non usato. Restituisce false se il codice non è valido.
     */
    public function consume(User $user, string $code): bool
    {
        $hash = self::hash(self::normalize($code));

        $updated = UserRecoveryCode::query()
            ->where('user_id', $user->id)
            ->where('code_hash', $hash)
            ->whereNull('used_at')
            ->update(['used_at' => now()]);

        return $updated === 1;
    }

    public function remaining(User $user): int
    {
        return $user->recoveryCodes()->whereNull('used_at')->count();
    }

    public static function hash(string $code): string
    {
        $key = config('app.key');

        if (! is_string($key) || $key === '') {
            throw new RuntimeException('APP_KEY mancante.');
        }

        return hash_hmac('sha256', self::normalize($code), $key);
    }

    private static function normalize(string $code): string
    {
        return Str::lower(str_replace([' ', '-'], '', trim($code)));
    }

    private function randomCode(): string
    {
        $chars = '';

        for ($i = 0; $i < 10; $i++) {
            $chars .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
        }

        return substr($chars, 0, 5).'-'.substr($chars, 5);
    }
}
