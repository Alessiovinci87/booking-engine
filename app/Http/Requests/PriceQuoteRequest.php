<?php

namespace App\Http\Requests;

use App\Domain\Pricing\Calculator\PriceRequest;
use App\Domain\Reservations\StayRange;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Corpo di POST /admin/units/{id}/price-preview e POST /public/{t}/{p}/quote-price (07): date di
 * soggiorno [check_in, check_out), adulti e bambini. Al massimo un anno di notti.
 */
final class PriceQuoteRequest extends FormRequest
{
    public const MAX_NIGHTS = 365;

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'check_in' => ['required', 'date_format:Y-m-d', 'after_or_equal:2000-01-01', 'before:2100-01-01'],
            'check_out' => ['required', 'date_format:Y-m-d', 'after:check_in', 'before:2100-01-01'],
            'adults' => ['required', 'integer', 'between:1,50'],
            'children' => ['sometimes', 'integer', 'between:0,50'],
        ];
    }

    /**
     * @return list<callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                if ($this->stay()->nights() > self::MAX_NIGHTS) {
                    $validator->errors()->add('check_out', __('pricing.validation.stay_too_long', ['max' => self::MAX_NIGHTS]));
                }
            },
        ];
    }

    public function stay(): StayRange
    {
        return StayRange::of($this->string('check_in')->toString(), $this->string('check_out')->toString());
    }

    public function toPriceRequest(): PriceRequest
    {
        return new PriceRequest($this->stay(), $this->integer('adults'), $this->integer('children'));
    }
}
