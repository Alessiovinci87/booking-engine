<?php

namespace App\Http\Requests\Admin;

use App\Domain\Reservations\Models\Reservation;
use App\Domain\Reservations\StayRange;
use App\Http\Requests\PriceQuoteRequest;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * PATCH /admin/reservations/{id} (07, T18): nuove date e/o ospiti con If-Match. I campi assenti
 * restano quelli attuali. `expected_total_cents` facoltativo, dall'anteprima. `note` descrive la modifica nello storico (ReservationChange).
 */
final class ModifyReservationRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'check_in' => ['required_with:check_out', 'date_format:Y-m-d', 'after_or_equal:2000-01-01', 'before:2100-01-01'],
            'check_out' => ['required_with:check_in', 'date_format:Y-m-d', 'after:check_in', 'before:2100-01-01'],
            'adults' => ['sometimes', 'integer', 'between:1,50'],
            'children' => ['sometimes', 'integer', 'between:0,50'],
            'note' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'expected_total_cents' => ['sometimes', 'nullable', 'integer', 'min:0'],
        ];
    }

    /**
     * @return list<callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->isEmpty() && $this->has('check_in')
                    && StayRange::of($this->string('check_in')->toString(), $this->string('check_out')->toString())->nights() > PriceQuoteRequest::MAX_NIGHTS) {
                    $validator->errors()->add('check_out', __('pricing.validation.stay_too_long', ['max' => PriceQuoteRequest::MAX_NIGHTS]));
                }
            },
        ];
    }

    public function stayFor(Reservation $reservation): StayRange
    {
        return $this->has('check_in')
            ? StayRange::of($this->string('check_in')->toString(), $this->string('check_out')->toString())
            : $reservation->stay;
    }

    public function adultsFor(Reservation $reservation): int
    {
        return $this->has('adults') ? $this->integer('adults') : $reservation->adults;
    }

    public function childrenFor(Reservation $reservation): int
    {
        return $this->has('children') ? $this->integer('children') : $reservation->children;
    }

    /** Totale visto nell'anteprima (ADR-0011 punto 28): se il prezzo nel frattempo è cambiato → 409 PRICE_CHANGED. */
    public function expectedTotalCents(): ?int
    {
        $value = $this->validated('expected_total_cents');

        return $value === null ? null : (int) $value;
    }

    public function note(): ?string
    {
        $note = $this->validated('note');

        return is_string($note) && trim($note) !== '' ? trim($note) : null;
    }
}
