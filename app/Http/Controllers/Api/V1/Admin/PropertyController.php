<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domain\Inventory\Models\Property;
use App\Domain\Inventory\PropertySetupService;
use App\Http\Api\IfMatch;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\PropertyRequest;
use App\Http\Resources\Admin\PropertyResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Strutture del pannello (07): GET/POST /admin/properties, GET/PATCH /admin/properties/{id},
 * POST /admin/properties/{id}/publish.
 *
 * I modelli si caricano dentro il contesto tenant (niente binding implicito, che girerebbe prima del
 * middleware tenant): una struttura di un altro tenant risulta inesistente, quindi 404 (08).
 */
final class PropertyController extends Controller
{
    public function __construct(private readonly PropertySetupService $setup) {}

    public function index(): JsonResponse
    {
        $properties = Property::query()
            ->with(['translations', 'cancellationPolicies'])
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();

        return new JsonResponse(['data' => PropertyResource::collection($properties)->resolve(), 'next_cursor' => null]);
    }

    public function store(PropertyRequest $request): JsonResponse
    {
        $property = $this->setup->create($request->attributesData(), $request->translations() ?? []);

        return self::respond($property, 201);
    }

    public function show(string $property): JsonResponse
    {
        return self::respond(Property::query()->findOrFail($property));
    }

    public function update(PropertyRequest $request, string $property): JsonResponse
    {
        $model = self::lock($property, $request);

        return self::respond($this->setup->update($model, $request->attributesData(), $request->translations()));
    }

    /**
     * Pubblica la struttura (07). Le guardie stanno in PropertySetupService; la riga è bloccata così due
     * richieste contemporanee non pubblicano due volte. Ripetere la richiesta su una struttura già
     * pubblicata restituisce la struttura senza cambiare nulla.
     */
    public function publish(string $property): JsonResponse
    {
        $model = Property::query()->lockForUpdate()->findOrFail($property);

        return self::respond($this->setup->publish($model));
    }

    /** Legge la struttura con lock di riga e verifica If-Match (07). */
    public static function lock(string $id, Request $request): Property
    {
        $model = Property::query()->lockForUpdate()->findOrFail($id);
        IfMatch::assert($request, $model->version);

        return $model;
    }

    public static function respond(Property $property, int $status = 200): JsonResponse
    {
        $property->loadMissing(['translations', 'cancellationPolicies']);

        return (new PropertyResource($property))->response()->setStatusCode($status)
            ->header('ETag', IfMatch::etag($property->version));
    }
}
