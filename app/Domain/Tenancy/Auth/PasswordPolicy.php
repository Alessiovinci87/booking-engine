<?php

namespace App\Domain\Tenancy\Auth;

use Illuminate\Validation\Rules\Password;

/**
 * Politica delle password del pannello (08): almeno 12 caratteri e controllo contro le password
 * compromesse note. Il controllo usa il servizio di Laravel (Have I Been Pwned, k-anonymity: si
 * inviano solo i primi 5 caratteri dell'hash SHA-1, mai la password né l'hash completo).
 *
 * L'hash della password è Argon2id (config/hashing.php).
 */
final class PasswordPolicy
{
    /** @return list<mixed> */
    public static function rules(): array
    {
        return [
            'required',
            'string',
            'max:200',
            Password::min((int) config('security.password.min_length'))->uncompromised(),
        ];
    }
}
