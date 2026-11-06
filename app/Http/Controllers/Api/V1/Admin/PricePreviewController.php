<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domain\Inventory\Models\Unit;
use App\Domain\Pricing\PriceQuoteService;
use App\Domain\Reservations\EffectiveCancellationPolicy;
use App\Domain\Tenancy\Enums\Locale;
use App\Http\Controllers\Controller;
use App\Http\Requests\PriceQuoteRequest;
use App\Http\Resources\PriceQuoteResource;
use Illuminate\Http\JsonResponse;

/**
 * POST /admin/units/{id}/price-preview (07): stesso calcolo della pagina ospite, senza creare nulla.
 * Errori MIN_NIGHTS_NOT_MET, OCCUPANCY_EXCEEDED, AMOUNT_BELOW_MINIMUM (422). Disponibilità da M3.
 */
final class PricePreviewController extends Controller
{
    public function __invoke(
        PriceQuoteRequest $request,
        string $unit,
        PriceQuoteService $prices,
        EffectiveCancellationPolicy $policies,
    ): JsonResponse {
        $model = Unit::query()->with('property')->findOrFail($unit);
        $priceRequest = $request->toPriceRequest();
        $quote = $prices->quote($model, $priceRequest);

        return new JsonResponse(PriceQuoteResource::make($model, $priceRequest, $quote, $policies->for($model), Locale::IT));
    }
}
