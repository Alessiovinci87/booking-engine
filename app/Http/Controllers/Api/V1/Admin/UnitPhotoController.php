<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domain\Inventory\Models\Unit;
use App\Domain\Inventory\Models\UnitPhoto;
use App\Domain\Inventory\Photos\UnitPhotoService;
use App\Http\Controllers\Controller;
use App\Http\Resources\Admin\UnitPhotoResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Rule;

/**
 * Foto dell'unità (07; 11, attività 2.2): elenco, upload multipart, ordine (la prima è la copertina),
 * eliminazione. Unità o foto di altri tenant: 404.
 */
final class UnitPhotoController extends Controller
{
    public function __construct(private readonly UnitPhotoService $photos) {}

    public function index(string $unit): JsonResponse
    {
        $model = Unit::query()->findOrFail($unit);

        return self::list($model);
    }

    public function store(Request $request, string $unit): JsonResponse
    {
        $model = Unit::query()->lockForUpdate()->findOrFail($unit);
        $maxSide = (int) config('photos.max_source_pixels_side');

        $request->validate([
            'photo' => ['required', 'file', 'max:'.(int) config('photos.max_upload_kb'),
                'mimetypes:image/jpeg,image/png,image/webp', 'mimes:jpg,jpeg,png,webp',
                'dimensions:max_width='.$maxSide.',max_height='.$maxSide],
        ]);

        /** @var UploadedFile $file */
        $file = $request->file('photo');
        $photo = $this->photos->upload($model, (string) $file->getRealPath());

        return (new UnitPhotoResource($photo))->response()->setStatusCode(201);
    }

    public function reorder(Request $request, string $unit): JsonResponse
    {
        $model = Unit::query()->lockForUpdate()->findOrFail($unit);

        $validated = $request->validate([
            'photo_ids' => ['required', 'list', 'max:'.(int) config('photos.max_per_unit')],
            'photo_ids.*' => ['required', 'uuid', 'distinct', Rule::exists(UnitPhoto::class, 'id')->where('unit_id', $model->id)],
        ]);

        /** @var list<string> $ids */
        $ids = $validated['photo_ids'];
        $this->photos->reorder($model, $ids);

        return self::list($model);
    }

    public function destroy(string $unit, string $photo): Response
    {
        $model = Unit::query()->lockForUpdate()->findOrFail($unit);
        $this->photos->delete(UnitPhoto::query()->where('unit_id', $model->id)->findOrFail($photo));

        return response()->noContent();
    }

    private static function list(Unit $unit): JsonResponse
    {
        $photos = UnitPhoto::query()->where('unit_id', $unit->id)->orderBy('position')->orderBy('id')->get();

        return new JsonResponse(['data' => UnitPhotoResource::collection($photos)->resolve()]);
    }
}
