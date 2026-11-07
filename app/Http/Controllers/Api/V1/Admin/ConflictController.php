<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domain\Calendar\ConflictService;
use App\Domain\Calendar\Models\ExternalCalendar;
use App\Domain\Calendar\Models\ExternalCalendarEvent;
use App\Domain\Inventory\Enums\ConflictResolution;
use App\Domain\Inventory\Enums\ConflictStatus;
use App\Domain\Inventory\InventoryService;
use App\Domain\Inventory\Models\InventoryConflict;
use App\Domain\Inventory\Models\Unit;
use App\Domain\Reservations\Models\Reservation;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Conflitti di inventario (07 "Conflitti", 03 sezione 5, 06):
 * - GET /admin/conflicts?status=OPEN: conflitti con unità, date, fonti (prenotazione, evento esterno);
 * - POST /admin/conflicts/{id}/investigate | resolve (`resolution`, `notes`) | ignore (`notes`);
 * - POST /admin/external-events/{id}/not-echo: un'eco sospetta è una prenotazione vera (06, ADR-0013).
 *
 * Nessuna azione cancella prenotazioni: la chiusura toglie solo il blocco del conflitto (I13).
 */
final class ConflictController extends Controller
{
    public function __construct(private readonly ConflictService $conflicts, private readonly InventoryService $inventory) {}

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['sometimes', 'array'],
            'status.*' => [Rule::enum(ConflictStatus::class)],
            'unit_id' => ['sometimes', 'uuid'],
        ]);
        $statuses = $validated['status'] ?? [ConflictStatus::OPEN->value, ConflictStatus::INVESTIGATING->value];

        $conflicts = InventoryConflict::query()
            ->whereIn('status', $statuses)
            ->when(isset($validated['unit_id']), fn ($q) => $q->where('unit_id', $validated['unit_id']))
            ->orderByRaw('lower(stay)')->orderBy('id')
            ->limit(200)->get();

        $units = Unit::query()->whereIn('id', $conflicts->pluck('unit_id'))->get()->keyBy('id');
        $eventIds = $conflicts->pluck('external_event_id')->merge($conflicts->pluck('other_external_event_id'))->filter()->unique();
        $events = ExternalCalendarEvent::query()->whereIn('id', $eventIds)->get()->keyBy('id');
        $calendars = ExternalCalendar::query()->whereIn('id', $events->pluck('external_calendar_id'))->get()->keyBy('id');
        $byOccupancy = $this->inventory->reservationIdsFor(array_values($conflicts->pluck('occupancy_id')->filter()->unique()->all()));
        $reservations = Reservation::query()->whereIn('id', array_filter($byOccupancy))->get()->keyBy('id');

        $event = function (?string $id) use ($events, $calendars): ?array {
            $e = $id === null ? null : $events->get($id);

            return $e === null ? null : [
                'id' => $e->id,
                'source' => $calendars->get($e->external_calendar_id)?->source->value,
                'check_in' => $e->stay->checkIn->toDateString(),
                'check_out' => $e->stay->checkOut->toDateString(),
                'summary' => $e->summary_redacted,
                'echo_kind' => $e->echo_kind->value,
                'status' => $e->status->value,
            ];
        };

        return new JsonResponse(['data' => $conflicts->map(function (InventoryConflict $c) use ($units, $event, $reservations, $byOccupancy): array {
            $reservationId = $c->occupancy_id === null ? null : ($byOccupancy[$c->occupancy_id] ?? null);
            $reservation = $reservationId === null ? null : $reservations->get($reservationId);

            return [
                ...self::present($c),
                'unit' => ['id' => $c->unit_id, 'name' => $units->get($c->unit_id)?->name],
                'external_event' => $event($c->external_event_id),
                'other_external_event' => $event($c->other_external_event_id),
                'kind' => $c->occupancy_id === null ? 'EXTERNAL' : ($reservationId === null ? 'MANUAL_BLOCK' : 'RESERVATION'),
                'reservation' => $reservation === null ? null : ['id' => $reservation->id, 'code' => $reservation->code, 'status' => $reservation->status->value],
            ];
        })->values()->all()]);
    }

    public function investigate(Request $request, string $conflict): JsonResponse
    {
        $model = InventoryConflict::query()->findOrFail($conflict);

        return new JsonResponse(['data' => self::present($this->conflicts->investigate($model, self::userId($request)))]);
    }

    public function resolve(Request $request, string $conflict): JsonResponse
    {
        $validated = $request->validate([
            'resolution' => ['required', Rule::enum(ConflictResolution::class)],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);
        $model = InventoryConflict::query()->findOrFail($conflict);
        $notes = isset($validated['notes']) && trim((string) $validated['notes']) !== '' ? trim((string) $validated['notes']) : null;

        return new JsonResponse(['data' => self::present($this->conflicts->resolve(
            $model, ConflictResolution::from((string) $validated['resolution']), $notes, self::userId($request),
        ))]);
    }

    public function ignore(Request $request, string $conflict): JsonResponse
    {
        $validated = $request->validate(['notes' => ['required', 'string', 'min:3', 'max:1000']]);
        $model = InventoryConflict::query()->findOrFail($conflict);

        return new JsonResponse(['data' => self::present($this->conflicts->ignore($model, trim((string) $validated['notes']), self::userId($request)))]);
    }

    public function notAnEcho(string $event): JsonResponse
    {
        $model = ExternalCalendarEvent::query()->findOrFail($event);
        $opened = $this->conflicts->notAnEcho($model);

        return new JsonResponse(['data' => [
            'id' => $model->id,
            'echo_kind' => $model->echo_kind->value,
            'conflicts' => array_map(fn (InventoryConflict $c) => self::present($c), $opened),
        ]]);
    }

    /** @return array<string, mixed> */
    private static function present(InventoryConflict $c): array
    {
        return [
            'id' => $c->id,
            'unit_id' => $c->unit_id,
            'check_in' => $c->stay->checkIn->toDateString(),
            'check_out' => $c->stay->checkOut->toDateString(),
            'status' => $c->status->value,
            'suspected_echo' => $c->suspected_echo,
            'occupancy_id' => $c->occupancy_id,
            'external_event_id' => $c->external_event_id,
            'other_external_event_id' => $c->other_external_event_id,
            'detected_at' => $c->detected_at->toIso8601ZuluString(),
            'resolution' => $c->resolution?->value,
            'resolution_notes' => $c->resolution_notes,
            'resolved_at' => $c->resolved_at?->toIso8601ZuluString(),
        ];
    }

    private static function userId(Request $request): ?string
    {
        $id = $request->user()?->getAuthIdentifier();

        return $id === null ? null : (string) $id;
    }
}
