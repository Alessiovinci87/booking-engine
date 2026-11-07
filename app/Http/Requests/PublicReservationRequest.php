<?php

namespace App\Http\Requests;

use App\Domain\Reservations\GuestDetails;
use App\Domain\Reservations\StayRange;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * POST /public/{t}/{p}/reservations (07): unità, date, ospiti, dati dell'ospite, lingua, versioni di
 * condizioni e informativa accettate e totale atteso. Il tenant viene dallo slug, mai dal corpo.
 */
final class PublicReservationRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'unit_id' => ['required', 'uuid'],
            'check_in' => ['required', 'date_format:Y-m-d', 'after_or_equal:2000-01-01', 'before:2100-01-01'],
            'check_out' => ['required', 'date_format:Y-m-d', 'after:check_in', 'before:2100-01-01'],
            'adults' => ['required', 'integer', 'between:1,50'],
            'children' => ['sometimes', 'integer', 'between:0,50'],
            'accepted_terms_version' => ['present', 'nullable', 'string', 'max:64'],
            'accepted_privacy_version' => ['required', 'string', 'max:64'],
            'expected_total_cents' => ['required', 'integer', 'min:0'],
            ...GuestRules::rules(),
        ];
    }

    /**
     * @return list<callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->isEmpty() && $this->stay()->nights() > PriceQuoteRequest::MAX_NIGHTS) {
                    $validator->errors()->add('check_out', __('pricing.validation.stay_too_long', ['max' => PriceQuoteRequest::MAX_NIGHTS]));
                }
            },
        ];
    }

    public function stay(): StayRange
    {
        return StayRange::of($this->string('check_in')->toString(), $this->string('check_out')->toString());
    }

    public function guest(): GuestDetails
    {
        return GuestRules::from($this->validated());
    }
}
