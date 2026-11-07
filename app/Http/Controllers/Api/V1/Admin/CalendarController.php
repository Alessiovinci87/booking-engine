<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domain\Calendar\Enums\ExternalEventStatus;
use App\Domain\Calendar\Models\ExternalCalendarEvent;
use App\Domain\Inventory\Enums\ConflictStatus;
use App\Domain\Inventory\InventoryService;
use App\Domain\Inventory\Models\InventoryConflict;
use App\Domain\Inventory\Models\Unit;
use App\Domain\Reservations\Models\Reservation;
use App\Domain\Reservations\StayRange;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * GET /admin/calendar?unit_id=&from=&to= (07): occupazioni attive (prenotazioni in ogni stato
 * occupante e blocchi manuali) con codice, stato e nome dell'ospite, eventi esterni con
 * classificazione eco e conflitti aperti, ciascuno con la propria origine. Al massimo 18 mesi.
 */
final class CalendarController extends Controller
{
    private const MAX_DAYS = 550;

    public function __invoke(Request $request, InventoryService $inventory): JsonResponse
    {
        $validated = $request->validate([
            'unit_id' => ['required', 'uuid'],
            'from' => ['required', 'date_format:Y-m-d', 'after_or_equal:2000-01-01'],
            'to' => ['required', 'date_format:Y-m-d', 'after:from', 'before:2100-01-01'],
        ]);
        $range = StayRange::of((string) $validated['from'], (string) $validated['to']);

        if ($range->nights() > self::MAX_DAYS) {
            throw ValidationException::withMessages(['to' => __('validation.max.numeric', ['attribute' => 'to', 'max' => self::MAX_DAYS])]);
        }

        $unit = Unit::query()->findOrFail((string) $validated['unit_id']);
        $occupancies = $inventory->calendarOccupancies($unit->id, $range);
        $reservationIds = array_values(array_filter(array_column($occupancies, 'reservation_id')));
        $reservations = Reservation::query()->with('guest')->whereIn('id', $reservationIds)->get()->keyBy('id');

        $occupancies = array_map(function (array $o) use ($reservations): array {
            $r = $o['reservation_id'] === null ? null : $reservations->get($o['reservation_id']);

            return [...$o, 'reservation' => $r === null ? null : [
                'id' => $r->id,
                'code' => $r->code,
                'status' => $r->status->value,
                'channel' => $r->channel->value,
                'guest_name' => trim($r->guest->first_name.' '.$r->guest->last_name),
                'adults' => $r->adults,
                'children' => $r->children,
            ]];
        }, $occupancies);

        $events = ExternalCalendarEvent::query()
            ->join('external_calendars as c', fn ($join) => $join->on('c.id', '=', 'external_calendar_events.external_calendar_id')
                ->on('c.tenant_id', '=', 'external_calendar_events.tenant_id'))
            ->where('c.unit_id', $unit->id)
            ->whereIn('external_calendar_events.status', [ExternalEventStatus::ACTIVE->value, ExternalEventStatus::PENDING_REMOVAL->value])
            ->whereRaw('external_calendar_events.stay && ?::daterange', [$range->toPostgres()])
            ->orderByRaw('lower(external_calendar_events.stay)')
            ->get(['external_calendar_events.*', 'c.source as calendar_source'])
            ->map(fn (ExternalCalendarEvent $e): array => [
                'id' => $e->id,
                'source' => $e->getAttribute('calendar_source'),
                'check_in' => $e->stay->checkIn->toDateString(),
                'check_out' => $e->stay->checkOut->toDateString(),
                'summary' => $e->summary_redacted,
                'echo_kind' => $e->echo_kind->value,
                'status' => $e->status->value,
            ])->all();

        $conflicts = InventoryConflict::query()
            ->where('unit_id', $unit->id)
            ->whereIn('status', [ConflictStatus::OPEN->value, ConflictStatus::INVESTIGATING->value])
            ->whereRaw('stay && ?::daterange', [$range->toPostgres()])
            ->orderByRaw('lower(stay)')
            ->get()
            ->map(fn (InventoryConflict $c): array => [
                'id' => $c->id,
                'check_in' => $c->stay->checkIn->toDateString(),
                'check_out' => $c->stay->checkOut->toDateString(),
                'status' => $c->status->value,
                'suspected_echo' => $c->suspected_echo,
            ])->all();

        return new JsonResponse([
            'unit_id' => $unit->id,
            'from' => $range->checkIn->toDateString(),
            'to' => $range->checkOut->toDateString(),
            'occupancies' => $occupancies,
            'external_events' => $events,
            'conflicts' => $conflicts,
        ]);
    }
}
