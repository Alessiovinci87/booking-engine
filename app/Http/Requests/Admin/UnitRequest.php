<?php

namespace App\Http\Requests\Admin;

use App\Domain\Inventory\Enums\BookingMode;
use App\Domain\Inventory\Enums\PaymentMode;
use App\Domain\Inventory\Enums\UnitStatus;
use App\Domain\Inventory\Models\Unit;
use App\Domain\Reservations\Models\CancellationPolicy;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Creazione (POST /properties/{id}/units) e modifica parziale (PATCH /units/{id}) di un'unità (07).
 * Importi in centesimi interi. La politica di cancellazione è facoltativa e solo tra quelle della
 * stessa struttura (ADR-0009). Lo stato (ACTIVE/INACTIVE) passa da UnitService; la sospensione delle
 * vendite ha un endpoint proprio.
 */
final class UnitRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $creating = $this->isMethod('POST');
        $required = $creating ? 'required' : 'sometimes';

        $rules = [
            'name' => [$required, 'string', 'max:200'],
            'base_occupancy' => [$required, 'integer', 'between:1,50'],
            'max_occupancy' => [$required, 'integer', 'between:1,50'],
            'booking_mode' => ['sometimes', Rule::enum(BookingMode::class)],
            'payment_mode' => ['sometimes', Rule::enum(PaymentMode::class)],
            'status' => ['sometimes', Rule::enum(UnitStatus::class)],
            'min_nights' => ['sometimes', 'integer', 'between:1,365'],
            'cleaning_fee_cents' => ['sometimes', 'integer', 'between:0,10000000'],
            'extra_guest_fee_cents' => ['sometimes', 'integer', 'between:0,10000000'],
            'cancellation_policy_id' => ['sometimes', 'nullable', 'uuid',
                Rule::exists(CancellationPolicy::class, 'id')->where('property_id', $this->propertyId())],
            ...TranslationRules::rules(),
        ];

        if ($creating) {
            $rules['base_nightly_cents'] = ['required', 'integer', 'between:1,10000000'];
        }

        return $rules;
    }

    /**
     * @return array<string, mixed>
     */
    public function attributesData(): array
    {
        $data = $this->validated();
        unset($data['translations'], $data['base_nightly_cents']);

        return $data;
    }

    /**
     * @return array<string, array<string, string|null>|null>|null
     */
    public function translations(): ?array
    {
        return TranslationRules::from($this->validated());
    }

    /** Struttura dell'unità: dal percorso in creazione, dall'unità (letta sotto RLS) in modifica. */
    private function propertyId(): ?string
    {
        if ($this->isMethod('POST')) {
            $id = $this->route('property');

            return is_string($id) ? $id : null;
        }

        $unitId = $this->route('unit');
        $propertyId = is_string($unitId) ? Unit::query()->whereKey($unitId)->value('property_id') : null;

        return is_string($propertyId) ? $propertyId : null;
    }
}
