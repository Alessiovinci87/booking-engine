<?php

namespace App\Http\Requests;

use App\Domain\Reservations\GuestDetails;
use App\Domain\Tenancy\Enums\Locale;
use Illuminate\Validation\Rule;

/**
 * Dati dell'ospite (02 Guest, 07): stesse regole per la prenotazione dell'ospite e per quella manuale
 * del gestore. Le note sono facoltative; la pagina ospite avvisa di non inserire dati sensibili (09).
 */
final class GuestRules
{
    /**
     * @return array<string, mixed>
     */
    public static function rules(): array
    {
        return [
            'guest' => ['required', 'array:first_name,last_name,email,phone,country_code,notes'],
            'guest.first_name' => ['required', 'string', 'max:100'],
            'guest.last_name' => ['required', 'string', 'max:100'],
            'guest.email' => ['required', 'string', 'email:rfc', 'max:254'],
            'guest.phone' => ['required', 'string', 'max:40', 'regex:/^\+?[0-9 ().\-]{6,40}$/'],
            'guest.country_code' => ['required', 'string', 'size:2', 'regex:/^[A-Z]{2}$/'],
            'guest.notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'locale' => ['required', Rule::enum(Locale::class)],
        ];
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    public static function from(array $validated): GuestDetails
    {
        /** @var array{first_name: string, last_name: string, email: string, phone: string, country_code: string, notes?: string|null} $guest */
        $guest = $validated['guest'];
        $notes = isset($guest['notes']) && trim($guest['notes']) !== '' ? trim($guest['notes']) : null;

        return new GuestDetails(
            trim($guest['first_name']),
            trim($guest['last_name']),
            mb_strtolower(trim($guest['email'])),
            trim($guest['phone']),
            $guest['country_code'],
            Locale::from((string) $validated['locale']),
            $notes,
        );
    }
}
