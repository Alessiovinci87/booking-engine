<?php

namespace App\Http\Controllers\Api\V1\Public;

use App\Domain\Inventory\AvailabilityService;
use App\Domain\Inventory\PublicCatalog;
use App\Domain\Pricing\PriceQuoteService;
use App\Domain\Reservations\EffectiveCancellationPolicy;
use App\Domain\Reservations\Models\CancellationPolicy;
use App\Http\Controllers\Controller;
use App\Http\Middleware\SetGuestLocale;
use App\Http\Requests\AvailabilityRequest;
use App\Http\Requests\PriceQuoteRequest;
use App\Http\Resources\PriceQuoteResource;
use App\Http\Resources\Public\PublicPropertyResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * API pubblica della struttura (07): GET /public/{t}/{p} e POST /public/{t}/{p}/quote-price.
 * Il tenant viene dallo slug (middleware tenant:public); bozze, unità inattive e tenant non attivi
 * risultano inesistenti (404).
 *
 * quote-price usa lo stesso calcolo dell'anteprima del pannello e non crea nulla. DATES_UNAVAILABLE
 * richiede AvailabilityService (11, attività 3.2) e arriva con M3.
 */
final class PropertyController extends Controller
{
    public function __construct(private readonly PublicCatalog $catalog) {}

    public function show(Request $request, string $tenantSlug, string $propertySlug): JsonResponse
    {
        $property = $this->catalog->property($propertySlug);
        $property->load('translations');

        $units = $this->catalog->units($property);
        $units->load(['translations', 'photos' => fn ($query) => $query->orderBy('position')]);

        $policies = CancellationPolicy::query()->where('property_id', $property->id)->get()->keyBy('id');

        return new JsonResponse(PublicPropertyResource::make($property, $units, $policies, SetGuestLocale::of($request)));
    }

    /** Notti non vendibili e minimi di notti dell'unità nell'intervallo (07, 02 "Disponibilità effettiva"). */
    public function availability(AvailabilityRequest $request, string $tenantSlug, string $propertySlug, AvailabilityService $availability): JsonResponse
    {
        $property = $this->catalog->property($propertySlug);
        $unit = $this->catalog->unit($property, $request->string('unit_id')->toString());
        $calendar = $availability->calendar($unit, $request->range());

        return new JsonResponse([...$calendar, 'min_nights_by_night' => (object) $calendar['min_nights_by_night']]);
    }

    public function quotePrice(
        PriceQuoteRequest $request,
        string $tenantSlug,
        string $propertySlug,
        PriceQuoteService $prices,
        EffectiveCancellationPolicy $policies,
        AvailabilityService $availability,
    ): JsonResponse {
        $property = $this->catalog->property($propertySlug);
        $unit = $this->catalog->unit($property, $request->string('unit_id')->toString());
        $priceRequest = $request->toPriceRequest();

        // 3.2 (ADR-0010): unità prenotabile e date libere prima del prezzo.
        $availability->assertBookable($unit);
        $availability->assertAvailable($unit, $priceRequest->stay);
        $quote = $prices->quote($unit, $priceRequest);

        return new JsonResponse(PriceQuoteResource::make($unit, $priceRequest, $quote, $policies->for($unit), SetGuestLocale::of($request)));
    }
}
