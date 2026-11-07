<?php

namespace App\Http\Requests;

use App\Domain\Reservations\StayRange;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * GET /public/{t}/{p}/availability?unit_id=&from=&to= (07): intervallo [from, to) di al massimo
 * 18 mesi (config inventory.availability_max_months).
 */
final class AvailabilityRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'unit_id' => ['required', 'uuid'],
            'from' => ['required', 'date_format:Y-m-d', 'after_or_equal:2000-01-01', 'before:2100-01-01'],
            'to' => ['required', 'date_format:Y-m-d', 'after:from', 'before:2100-01-01'],
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

                $months = (int) config('inventory.availability_max_months');
                $range = $this->range();

                if ($range->checkOut->greaterThan($range->checkIn->addMonthsNoOverflow($months))) {
                    $validator->errors()->add('to', __('inventory.availability.range_too_long', ['months' => $months]));
                }
            },
        ];
    }

    public function range(): StayRange
    {
        return StayRange::of($this->string('from')->toString(), $this->string('to')->toString());
    }
}
