<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domain\Inventory\Models\Property;
use App\Domain\Inventory\Models\Unit;
use App\Domain\Inventory\UnitService;
use App\Http\Api\IfMatch;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UnitRequest;
use App\Http\Resources\Admin\UnitResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Unità del pannello (07): GET/POST /admin/properties/{id}/units, GET/PATCH /admin/units/{id}.
 * Risorse di altri tenant: 404 (RLS e scope del tenant).
 */
final class UnitController extends Controller
{
    public function __construct(private readonly UnitService $units) {}

    public function index(string $property): JsonResponse
    {
        $model = Property::query()->findOrFail($property);
        $units = $model->units()->with('translations')->orderBy('created_at')->orderBy('id')->get();

        return new JsonResponse(['data' => UnitResource::collection($units)->resolve(), 'next_cursor' => null]);
    }

    public function store(UnitRequest $request, string $property): JsonResponse
    {
        $model = Property::query()->findOrFail($property);
        $base = $request->validated('base_nightly_cents');
        $unit = $this->units->create($model, $request->attributesData(), is_numeric($base) ? (int) $base : 0, $request->translations() ?? []);

        return self::respond($unit, 201);
    }

    public function show(string $unit): JsonResponse
    {
        return self::respond(Unit::query()->findOrFail($unit));
    }

    public function update(UnitRequest $request, string $unit): JsonResponse
    {
        $model = self::lock($unit, $request);

        return self::respond($this->units->update($model, $request->attributesData(), $request->translations()));
    }

    /** Legge l'unità con lock di riga e verifica If-Match (07). */
    public static function lock(string $id, Request $request): Unit
    {
        $model = Unit::query()->lockForUpdate()->findOrFail($id);
        IfMatch::assert($request, $model->version);

        return $model;
    }

    public static function respond(Unit $unit, int $status = 200): JsonResponse
    {
        $unit->loadMissing('translations');

        return (new UnitResource($unit))->response()->setStatusCode($status)
            ->header('ETag', IfMatch::etag($unit->version));
    }
}
