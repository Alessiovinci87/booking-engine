<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domain\Calendar\Enums\ChannelSource;
use App\Domain\Calendar\Enums\IcsFeedStatus;
use App\Domain\Calendar\IcsFeedLifecycle;
use App\Domain\Calendar\Models\IcsFeed;
use App\Domain\Inventory\Models\Unit;
use App\Http\Controllers\Controller;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Feed iCal esportati di un'unità (07 "Calendario", 06; 5.5):
 * - GET /admin/units/{id}/ics-feeds: feed con URL, stato, ultima lettura dell'OTA e avviso se un feed
 *   ACTIVE non è letto da oltre 24 ore (08);
 * - POST /admin/units/{id}/ics-feeds (`destination`): un feed per destinazione, accoppiato al
 *   calendario importato dallo stesso canale; 409 ICS_FEED_EXISTS se c'è già;
 * - POST /admin/ics-feeds/{id}/rotate: nuovo token, il vecchio resta FROZEN 7 giorni.
 */
final class IcsFeedController extends Controller
{
    public function __construct(private readonly IcsFeedLifecycle $lifecycle) {}

    public function index(string $unit): JsonResponse
    {
        $model = Unit::query()->findOrFail($unit);
        $order = ['ACTIVE' => 0, 'FROZEN' => 1, 'RETIRED' => 2];
        $feeds = IcsFeed::query()->where('unit_id', $model->id)->orderByDesc('created_at')->get()
            ->sortBy(fn (IcsFeed $f) => [$order[$f->status->value], $f->destination->value])->values();

        return new JsonResponse(['data' => $feeds->map(fn (IcsFeed $f) => self::present($f))->all()]);
    }

    public function store(Request $request, string $unit): JsonResponse
    {
        $validated = $request->validate(['destination' => ['required', Rule::enum(ChannelSource::class)]]);
        $model = Unit::query()->findOrFail($unit);

        return new JsonResponse(['data' => self::present($this->lifecycle->create($model, ChannelSource::from((string) $validated['destination'])))], 201);
    }

    public function rotate(string $feed): JsonResponse
    {
        $model = IcsFeed::query()->findOrFail($feed);

        return new JsonResponse(['data' => self::present($this->lifecycle->rotate($model))], 201);
    }

    /** @return array<string, mixed> */
    public static function present(IcsFeed $f): array
    {
        $readSince = $f->last_fetched_at ?? CarbonImmutable::instance($f->created_at ?? now());

        return [
            'id' => $f->id,
            'unit_id' => $f->unit_id,
            'destination' => $f->destination->value,
            'status' => $f->status->value,
            'url' => $f->status === IcsFeedStatus::RETIRED ? null : IcsFeedLifecycle::url($f),
            'paired_external_calendar_id' => $f->paired_external_calendar_id,
            'last_fetched_at' => $f->last_fetched_at?->toIso8601ZuluString(),
            'last_fetch_user_agent' => $f->last_fetch_user_agent,
            'not_read_warning' => $f->status === IcsFeedStatus::ACTIVE
                && $readSince->lessThan(CarbonImmutable::now()->subHours(IcsFeedLifecycle::STALE_READ_HOURS)),
            'frozen_at' => $f->frozen_at?->toIso8601ZuluString(),
            'rotated_at' => $f->rotated_at?->toIso8601ZuluString(),
            'retires_at' => $f->status === IcsFeedStatus::FROZEN && $f->rotated_at !== null
                ? $f->rotated_at->addDays(IcsFeedLifecycle::ROTATION_GRACE_DAYS)->toIso8601ZuluString() : null,
        ];
    }
}
