<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domain\Payments\Enums\RefundStatus;
use App\Domain\Payments\Models\Payment;
use App\Domain\Payments\Models\Refund;
use App\Domain\Payments\RefundService;
use App\Domain\Reservations\Models\Reservation;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Rimborsi da gestire (07, 03 sezione 3, 4.7):
 * - GET /admin/refunds?status=FAILED: rimborsi con prenotazione, importo e tentativi;
 * - POST /admin/refunds/{id}/retry: FAILED → PENDING e nuova chiamata a Stripe dopo il commit;
 * - POST /admin/refunds/{id}/mark-offline: FAILED → SUCCEEDED con metodo OFFLINE, nota obbligatoria.
 *
 * Rimborsi di altri tenant: 404 (RLS).
 */
final class RefundController extends Controller
{
    public function __construct(private readonly RefundService $refunds) {}

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['sometimes', 'array'],
            'status.*' => [Rule::enum(RefundStatus::class)],
        ]);

        $query = Refund::query()->orderByDesc('created_at')->orderByDesc('id')->limit(200);

        if (isset($validated['status'])) {
            $query->whereIn('status', $validated['status']);
        }

        $refunds = $query->get();
        $payments = Payment::query()->whereIn('id', $refunds->pluck('payment_id'))->get()->keyBy('id');
        $reservations = Reservation::query()->whereIn('id', $payments->pluck('reservation_id'))->get()->keyBy('id');

        return new JsonResponse(['data' => $refunds->map(function (Refund $refund) use ($payments, $reservations): array {
            $reservation = $reservations->get($payments->get($refund->payment_id)?->reservation_id);

            return [
                ...self::present($refund),
                'reservation' => $reservation === null ? null : ['id' => $reservation->id, 'code' => $reservation->code, 'status' => $reservation->status->value],
            ];
        })->values()->all()]);
    }

    public function retry(string $refund): JsonResponse
    {
        $model = Refund::query()->findOrFail($refund);

        return new JsonResponse(['data' => self::present($this->refunds->retry($model))]);
    }

    public function markOffline(Request $request, string $refund): JsonResponse
    {
        $validated = $request->validate(['note' => ['required', 'string', 'min:3', 'max:500']]);
        $model = Refund::query()->findOrFail($refund);

        return new JsonResponse(['data' => self::present($this->refunds->markOffline($model, trim((string) $validated['note'])))]);
    }

    /** @return array<string, mixed> */
    private static function present(Refund $refund): array
    {
        return [
            'id' => $refund->id,
            'payment_id' => $refund->payment_id,
            'amount_cents' => $refund->amount_cents,
            'method' => $refund->method->value,
            'status' => $refund->status->value,
            'attempts' => $refund->attempts,
            'next_attempt_at' => $refund->next_attempt_at?->toIso8601ZuluString(),
            'completed_at' => $refund->completed_at?->toIso8601ZuluString(),
            'created_at' => $refund->created_at?->toIso8601ZuluString(),
        ];
    }
}
