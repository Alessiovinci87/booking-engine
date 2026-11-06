<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domain\Inventory\Models\Property;
use App\Http\Controllers\Controller;
use App\Http\Resources\Admin\PropertyResource;
use Illuminate\Support\Str;

/**
 * GET /api/v1/admin/properties/{id} (07). In M1 solo lettura di base, usata anche dal test di
 * isolamento; M2.1 completa traduzioni e modifica.
 *
 * Il modello si carica dentro il contesto tenant (niente binding implicito, che girerebbe prima del
 * middleware tenant): una struttura di un altro tenant risulta inesistente, quindi 404 (08).
 */
final class PropertyController extends Controller
{
    public function show(string $property): PropertyResource
    {
        abort_unless(Str::isUuid($property), 404);

        return new PropertyResource(Property::query()->findOrFail($property));
    }
}
