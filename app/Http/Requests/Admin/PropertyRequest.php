<?php

namespace App\Http\Requests\Admin;

use App\Domain\Reservations\Models\CancellationPolicy;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Creazione (POST) e modifica parziale (PATCH) di una struttura (07). Lo stato non si invia mai: cambia
 * solo con la pubblicazione. Il tenant viene dal contesto, quindi slug unico e politica della stessa
 * struttura si verificano sotto RLS.
 */
final class PropertyRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $creating = $this->isMethod('POST');
        $propertyId = $this->route('property');
        $required = $creating ? 'required' : 'sometimes';

        $rules = [
            'name' => [$required, 'string', 'max:200'],
            'slug' => [$required, 'string', 'max:100', 'regex:/^[a-z0-9]([a-z0-9-]{0,98}[a-z0-9])?$/',
                Rule::unique('properties', 'slug')->ignore(is_string($propertyId) ? $propertyId : null)],
            'address_line1' => ['sometimes', 'nullable', 'string', 'max:200'],
            'address_line2' => ['sometimes', 'nullable', 'string', 'max:200'],
            'postal_code' => ['sometimes', 'nullable', 'string', 'max:20'],
            'city' => ['sometimes', 'nullable', 'string', 'max:100'],
            'province' => ['sometimes', 'nullable', 'string', 'max:100'],
            'country_code' => ['sometimes', 'string', 'size:2', 'regex:/^[A-Z]{2}$/'],
            'cin_code' => ['sometimes', 'nullable', 'string', 'max:50'],
            'timezone' => ['sometimes', 'string', 'timezone:all'],
            'contact_email' => ['sometimes', 'nullable', 'string', 'email', 'max:254'],
            'contact_phone' => ['sometimes', 'nullable', 'string', 'max:30'],
            'checkin_time' => ['sometimes', 'nullable', 'date_format:H:i'],
            'checkout_time' => ['sometimes', 'nullable', 'date_format:H:i'],
            'privacy_policy_url' => ['sometimes', 'nullable', 'string', 'url:https', 'max:2000'],
            'terms_url' => ['sometimes', 'nullable', 'string', 'url:https', 'max:2000'],
            'ota_commission_bps' => ['sometimes', 'integer', 'between:0,9999'],
            'approval_window_hours' => ['sometimes', 'integer', 'between:1,48'],
            ...TranslationRules::rules(),
        ];

        // Alla creazione le politiche della struttura non esistono ancora: la predefinita si sceglie dopo.
        if (! $creating) {
            $rules['default_cancellation_policy_id'] = ['sometimes', 'nullable', 'uuid',
                Rule::exists(CancellationPolicy::class, 'id')->where('property_id', $propertyId)];
        }

        return $rules;
    }

    /**
     * @return array<string, mixed>
     */
    public function attributesData(): array
    {
        $data = $this->validated();
        unset($data['translations']);

        return $data;
    }

    /**
     * @return array<string, array<string, string|null>|null>|null
     */
    public function translations(): ?array
    {
        return TranslationRules::from($this->validated());
    }
}
