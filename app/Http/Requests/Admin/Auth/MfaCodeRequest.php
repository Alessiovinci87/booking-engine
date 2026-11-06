<?php

namespace App\Http\Requests\Admin\Auth;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Codice TOTP a 6 cifre oppure codice di recupero (solo in verifica).
 */
final class MfaCodeRequest extends FormRequest
{
    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'code' => ['required_without:recovery_code', 'nullable', 'string', 'max:16'],
            'recovery_code' => ['required_without:code', 'nullable', 'string', 'max:32'],
        ];
    }
}
