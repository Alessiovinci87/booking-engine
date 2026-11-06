<?php

namespace App\Http\Resources\Admin;

use App\Domain\Inventory\Models\Property;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property Property $resource
 */
final class PropertyResource extends JsonResource
{
    public static $wrap = null;

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource->id,
            'name' => $this->resource->name,
            'slug' => $this->resource->slug,
            'status' => $this->resource->status->value,
            'timezone' => $this->resource->timezone,
        ];
    }
}
