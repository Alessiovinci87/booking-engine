<?php

namespace App\Http\Requests;

use App\Domain\Reservations\GuestDetails;
use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /public/quotes/{token}/accept (07): dati dell'ospite (precompilati dalla pagina), lingua,
 * versioni di condizioni e informativa accettate. Date, ospiti e prezzo vengono dal preventivo, mai
 * dal corpo; il tenant dal token.
 */
final class AcceptQuoteRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'accepted_terms_version' => ['present', 'nullable', 'string', 'max:64'],
            'accepted_privacy_version' => ['required', 'string', 'max:64'],
            ...GuestRules::rules(),
        ];
    }

    public function guest(): GuestDetails
    {
        return GuestRules::from($this->validated());
    }
}
