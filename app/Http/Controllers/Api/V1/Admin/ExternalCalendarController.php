<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domain\Calendar\CalendarChecklist;
use App\Domain\Calendar\Enums\ChannelSource;
use App\Domain\Calendar\Enums\ExternalEventStatus;
use App\Domain\Calendar\ExternalCalendarService;
use App\Domain\Calendar\IcalImportService;
use App\Domain\Calendar\Models\ExternalCalendar;
use App\Domain\Calendar\Models\ExternalCalendarEvent;
use App\Domain\Calendar\Models\SyncRun;
use App\Domain\Calendar\SyncHealthService;
use App\Domain\Inventory\Models\Unit;
use App\Domain\Tenancy\Http\WithTenantContext;
use App\Domain\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Calendari importati e checklist di collegamento (07 "Calendario", 06; 5.6):
 *
 * - GET /admin/units/{id}/external-calendars: calendari con salute, ultimo successo, errori e prossimo giro;
 *   dell'URL solo l'host (il percorso può contenere il token privato dell'OTA);
 * - POST /admin/units/{id}/external-calendars (`source`, `url`, `switch_to_request`): prima sincronizzazione
 *   obbligatoria; 422 INVALID_ICAL_URL; 409 UNIT_IS_INSTANT;
 * - PATCH /admin/external-calendars/{id} (`url` oppure `status` ACTIVE/DISABLED); DELETE: rimozione;
 * - POST /admin/external-calendars/{id}/sync-now (max 1 ogni 2 min); GET .../runs: storico;
 * - POST /admin/external-events/{id}/remove: rimozione manuale di un evento;
 * - GET /admin/units/{id}/calendar-checklist, POST .../calendar-checklist/confirm (`step`).
 *
 * Le rotte con download (creazione, cambio di URL, sync-now) girano a passi (`tenant:panel,steps`).
 */
final class ExternalCalendarController extends Controller
{
    public function __construct(
        private readonly ExternalCalendarService $service,
        private readonly TenantContext $tenants,
    ) {}

    public function index(string $unit): JsonResponse
    {
        $model = Unit::query()->findOrFail($unit);
        $calendars = ExternalCalendar::query()->where('unit_id', $model->id)->orderBy('created_at')->get();
        $counts = ExternalCalendarEvent::query()->whereIn('external_calendar_id', $calendars->pluck('id'))
            ->where('status', '<>', ExternalEventStatus::REMOVED->value)
            ->selectRaw('external_calendar_id, count(*) as n')->groupBy('external_calendar_id')->pluck('n', 'external_calendar_id');

        return new JsonResponse(['data' => $calendars->map(fn (ExternalCalendar $c) => self::present($c, (int) ($counts[$c->id] ?? 0)))->values()->all()]);
    }

    public function store(Request $request, string $unit): JsonResponse
    {
        $validated = $request->validate([
            'source' => ['required', Rule::enum(ChannelSource::class)],
            'url' => ['required', 'string', 'max:2048'],
            'switch_to_request' => ['sometimes', 'boolean'],
        ]);
        $tenantId = self::tenant($request);

        $result = $this->service->create($tenantId, $unit, ChannelSource::from((string) $validated['source']), trim((string) $validated['url']), (bool) ($validated['switch_to_request'] ?? false));

        return new JsonResponse(['data' => [...self::present($result['calendar'], $result['run']->events_added), 'first_sync' => self::run($result['run'])]], 201);
    }

    public function update(Request $request, string $calendar): JsonResponse
    {
        $validated = $request->validate([
            'url' => ['required_without:status', 'string', 'max:2048'],
            'status' => ['required_without:url', Rule::in(['ACTIVE', 'DISABLED'])],
        ]);
        $tenantId = self::tenant($request);
        $run = null;

        if (isset($validated['url'])) {
            $this->tenants->run($tenantId, fn () => ExternalCalendar::query()->findOrFail($calendar));
            $run = $this->service->changeUrl($tenantId, $calendar, trim((string) $validated['url']));
        }

        $model = $this->tenants->run($tenantId, function () use ($calendar, $validated): ExternalCalendar {
            $model = ExternalCalendar::query()->lockForUpdate()->findOrFail($calendar);

            if (isset($validated['status']) && $validated['status'] !== $model->status->value
                && ($validated['status'] === 'DISABLED' || $model->status->value === 'DISABLED')) {
                $validated['status'] === 'DISABLED' ? $this->service->disable($model) : $this->service->enable($model);
            }

            return $model->refresh();
        });

        return new JsonResponse(['data' => [...self::present($model), 'last_run' => $run === null ? null : self::run($run)]]);
    }

    public function destroy(string $calendar): JsonResponse
    {
        $model = ExternalCalendar::query()->lockForUpdate()->findOrFail($calendar);
        $removed = $this->service->remove($model);

        return new JsonResponse(['data' => [...self::present($model->refresh()), 'events_removed' => $removed]]);
    }

    public function syncNow(Request $request, string $calendar): JsonResponse
    {
        $tenantId = self::tenant($request);
        $this->tenants->run($tenantId, fn () => ExternalCalendar::query()->findOrFail($calendar));
        $run = $this->service->syncNow($tenantId, $calendar);
        $model = $this->tenants->run($tenantId, fn () => ExternalCalendar::query()->findOrFail($calendar));

        return new JsonResponse(['data' => [...self::present($model), 'last_run' => $run === null ? null : self::run($run)]]);
    }

    public function runs(string $calendar): JsonResponse
    {
        $model = ExternalCalendar::query()->findOrFail($calendar);
        $runs = SyncRun::query()->where('external_calendar_id', $model->id)->orderByDesc('started_at')->limit(50)->get();

        return new JsonResponse(['data' => $runs->map(fn (SyncRun $r) => self::run($r))->values()->all()]);
    }

    public function removeEvent(string $event): JsonResponse
    {
        $model = ExternalCalendarEvent::query()->lockForUpdate()->findOrFail($event);
        $this->service->removeEvent($model);

        return new JsonResponse(['data' => ['id' => $model->id, 'status' => $model->status->value]]);
    }

    public function checklist(string $unit, CalendarChecklist $checklist): JsonResponse
    {
        return new JsonResponse(['data' => $checklist->for(Unit::query()->findOrFail($unit))]);
    }

    public function confirmChecklist(Request $request, string $unit, CalendarChecklist $checklist): JsonResponse
    {
        $validated = $request->validate(['step' => ['required', 'string', 'max:64']]);
        $id = $request->user()?->getAuthIdentifier();

        return new JsonResponse(['data' => $checklist->confirm(Unit::query()->lockForUpdate()->findOrFail($unit), (string) $validated['step'], $id === null ? null : (string) $id)]);
    }

    /** @return array<string, mixed> */
    public static function present(ExternalCalendar $c, ?int $activeEvents = null): array
    {
        $now = CarbonImmutable::now();

        return [
            'id' => $c->id,
            'unit_id' => $c->unit_id,
            'source' => $c->source->value,
            'url_host' => parse_url($c->url_encrypted, PHP_URL_HOST) ?: null,
            'status' => $c->status->value,
            'health' => $c->status->value === 'DISABLED' ? 'DISABLED' : SyncHealthService::healthFor($c, $now)->value,
            'last_success_at' => $c->last_success_at?->toIso8601ZuluString(),
            'last_attempt_at' => $c->last_attempt_at?->toIso8601ZuluString(),
            'consecutive_failures' => $c->consecutive_failures,
            'next_poll_at' => $c->next_poll_at?->toIso8601ZuluString(),
            'url_changed_at' => $c->url_changed_at?->toIso8601ZuluString(),
            'active_events' => $activeEvents,
            'poll_minutes' => IcalImportService::POLL_MINUTES,
            'demo' => $c->demo,
        ];
    }

    /** @return array<string, mixed> */
    private static function run(SyncRun $r): array
    {
        return [
            'id' => $r->id,
            'started_at' => $r->started_at->toIso8601ZuluString(),
            'finished_at' => $r->finished_at?->toIso8601ZuluString(),
            'outcome' => $r->outcome?->value,
            'http_status' => $r->http_status,
            'error_code' => $r->error_code,
            'events_seen' => $r->events_seen,
            'events_added' => $r->events_added,
            'events_updated' => $r->events_updated,
            'events_pending_removal' => $r->events_pending_removal,
            'events_removed' => $r->events_removed,
            'duration_ms' => $r->duration_ms,
        ];
    }

    private static function tenant(Request $request): string
    {
        return (string) $request->attributes->get(WithTenantContext::TENANT_ATTRIBUTE);
    }
}
