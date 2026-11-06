<?php

namespace App\Http\Requests\Admin;

use App\Domain\Payments\Enums\OfflineMethod;
use App\Domain\Reservations\GuestDetails;
use App\Domain\Reservations\StayRange;
use App\Http\Requests\GuestRules;
use App\Http\Requests\PriceQuoteRequest;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * POST /admin/reservations (07, T2): prenotazione manuale del gestore, confermata anche senza
 * pagamento. Il prezzo lo calcola il server (PriceCalculator) con lo sconto facoltativo; il pagamento
 * offline iniziale è facoltativo. Il tenant non arriva mai dal client.
 */
final class ManualReservationRequest extends FormRequest
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
            'discount_cents' => ['sometimes', 'integer', 'between:0,100000000'],
            'offline_payment' => ['sometimes', 'nullable', 'array:amount_cents,method'],
            'offline_payment.amount_cents' => ['required_with:offline_payment', 'integer', 'between:1,100000000'],
            'offline_payment.method' => ['required_with:offline_payment', Rule::enum(OfflineMethod::class)],
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

    public function offlineAmount(): ?int
    {
        $amount = $this->validated('offline_payment.amount_cents');

        return is_numeric($amount) ? (int) $amount : null;
    }

    public function offlineMethod(): ?OfflineMethod
    {
        $method = $this->validated('offline_payment.method');

        return is_string($method) ? OfflineMethod::from($method) : null;
    }
}
