<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domain\Payments\Models\Payment;
use App\Domain\Payments\Models\PaymentEvent;
use App\Domain\Reservations\Models\Reservation;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

/**
 * GET /admin/disputes (4.10): contestazioni aperte da Stripe (dispute_created senza dispute_closed),
 * con prenotazione e importo, per l'avviso nel pannello. La risposta alla contestazione si gestisce
 * nella dashboard Stripe del gestore (05 "Contestazioni"). Solo ID, codici e importi.
 */
final class DisputeController extends Controller
{
    public function index(): JsonResponse
    {
        $closed = PaymentEvent::query()->where('type', 'dispute_closed')->pluck('stripe_object_id')->filter()->all();

        $events = PaymentEvent::query()->where('type', 'dispute_created')
            ->whereNotIn('stripe_object_id', $closed)
            ->orderByDesc('occurred_at')->limit(100)->get();

        $payments = Payment::query()->whereIn('id', $events->pluck('payment_id'))->get()->keyBy('id');
        $reservations = Reservation::query()->whereIn('id', $payments->pluck('reservation_id'))->get()->keyBy('id');

        return new JsonResponse(['data' => $events->map(function (PaymentEvent $event) use ($payments, $reservations): array {
            $reservation = $reservations->get($payments->get($event->payment_id)?->reservation_id);

            return [
                'dispute_id' => $event->stripe_object_id,
                'payment_id' => $event->payment_id,
                'amount_cents' => $event->amount_cents,
                'opened_at' => $event->occurred_at->toIso8601ZuluString(),
                'reservation' => $reservation === null ? null : ['id' => $reservation->id, 'code' => $reservation->code, 'status' => $reservation->status->value],
            ];
        })->values()->all()]);
    }
}
