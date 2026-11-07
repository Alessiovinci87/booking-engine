<?php

namespace App\Domain\Tenancy\Models;

use App\Domain\Privacy\PersonalData;
use App\Domain\Tenancy\Enums\Locale;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;

/**
 * Utente del pannello (02). Tabella globale: il tenant si ricava dalle membership tramite la funzione
 * app_user_tenants (08). Password Argon2id; segreto MFA cifrato a riposo (08).
 *
 * @property string $id
 * @property string $email
 * @property string $password_hash
 * @property string|null $mfa_secret_encrypted
 * @property CarbonImmutable|null $mfa_enabled_at
 * @property CarbonImmutable|null $sessions_revoked_at
 * @property Locale $locale
 * @property bool $is_platform_operator operatore di piattaforma per l'accesso di supporto (7.5)
 */
#[PersonalData('email')]
class User extends Authenticatable
{
    use HasUuids;

    protected $authPasswordName = 'password_hash';

    /**
     * Timestamp salvati al microsecondo (timestamptz): `sessions_revoked_at` si confronta con l'inizio
     * della sessione con la stessa precisione (6.11, ADR-0016), così una sessione aperta subito dopo una
     * revoca non viene chiusa.
     */
    protected $dateFormat = 'Y-m-d H:i:s.uO';

    /** @var list<string> */
    protected $fillable = ['email', 'password_hash', 'locale'];

    /** @var list<string> */
    protected $hidden = ['password_hash', 'mfa_secret_encrypted'];

    protected function casts(): array
    {
        return [
            'password_hash' => 'hashed',
            'mfa_secret_encrypted' => 'encrypted',
            'mfa_enabled_at' => 'immutable_datetime',
            'sessions_revoked_at' => 'immutable_datetime',
            'locale' => Locale::class,
            'is_platform_operator' => 'boolean',
        ];
    }

    public function hasMfaEnabled(): bool
    {
        return $this->mfa_enabled_at !== null && $this->mfa_secret_encrypted !== null;
    }

    /** @return HasMany<UserRecoveryCode, $this> */
    public function recoveryCodes(): HasMany
    {
        return $this->hasMany(UserRecoveryCode::class);
    }

    /** Email normalizzata: l'unicità nel database è case-insensitive (02). */
    public static function normalizeEmail(string $email): string
    {
        return mb_strtolower(trim($email));
    }
}
