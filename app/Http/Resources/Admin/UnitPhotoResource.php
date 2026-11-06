<?php

namespace App\Http\Resources\Admin;

use App\Domain\Inventory\Models\UnitPhoto;
use App\Domain\Inventory\Photos\UnitPhotoService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Foto con URL firmati temporanei (validi un'ora) delle due misure. Usata dal pannello e dall'API
 * pubblica: non contiene il percorso sul disco.
 *
 * @property UnitPhoto $resource
 */
final class UnitPhotoResource extends JsonResource
{
    public static $wrap = null;

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $photo = $this->resource;

        return [
            'id' => $photo->id,
            'position' => $photo->position,
            'is_cover' => $photo->position === 0,
            'width' => $photo->width,
            'height' => $photo->height,
            'urls' => app(UnitPhotoService::class)->urls($photo),
        ];
    }
}
