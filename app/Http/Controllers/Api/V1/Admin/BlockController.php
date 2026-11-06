<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domain\Inventory\InventoryService;
use App\Domain\Inventory\Models\Unit;
use App\Domain\Reservations\StayRange;
use App\Http\Controllers\Controller;
use App\Http\Requests\PriceQuoteRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;

/**
 * Blocchi manuali del gestore (07): POST/DELETE /admin/units/{id}/blocks[/{blockId}]. Un blocco è
 * un'occupazione MANUAL_BLOCK FIRM scritta solo da InventoryService; le date devono essere libere
 * (nessuna occupazione, evento esterno o conflitto). Ammesso anche su unità sospese o inattive.
 */
final class BlockController extends Controller
{
    public function __construct(private readonly InventoryService $inventory) {}

    public function store(Request $request, string $unit): JsonResponse
    {
        $validated = $request->validate([
            'check_in' => ['required', 'date_format:Y-m-d', 'after_or_equal:2000-01-01', 'before:2100-01-01'],
            'check_out' => ['required', 'date_format:Y-m-d', 'after:check_in', 'before:2100-01-01'],
            'note' => ['sometimes', 'nullable', 'string', 'max:500'],
        ]);
        $stay = StayRange::of((string) $validated['check_in'], (string) $validated['check_out']);

        if ($stay->nights() > PriceQuoteRequest::MAX_NIGHTS) {
            throw ValidationException::withMessages(['check_out' => __('pricing.validation.stay_too_long', ['max' => PriceQuoteRequest::MAX_NIGHTS])]);
        }

        $note = isset($validated['note']) && trim((string) $validated['note']) !== '' ? trim((string) $validated['note']) : null;
        $block = $this->inventory->acquireBlock(Unit::query()->findOrFail($unit), $stay, $note);

        return new JsonResponse(InventoryService::view($block), 201);
    }

    public function destroy(string $unit, string $block): Response
    {
        $this->inventory->releaseBlock($block, 'block_removed', $unit);

        return response()->noContent();
    }
}
