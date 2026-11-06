<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domain\Inventory\Models\Unit;
use App\Domain\Pricing\Models\RatePlan;
use App\Domain\Pricing\Models\SeasonRule;
use App\Domain\Pricing\RatePlanService;
use App\Http\Api\IfMatch;
use App\Http\Controllers\Controller;
use App\Http\Resources\Admin\RatePlanResource;
use App\Http\Resources\Admin\SeasonResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Tariffe dell'unità (07; 11, attività 2.3): GET/PATCH /units/{id}/rate-plan e
 * GET/POST/PATCH/DELETE /units/{id}/seasons[/{seasonId}]. Importi in centesimi interi; notti delle
 * stagioni come prima e ultima notte comprese. Le PATCH richiedono If-Match.
 */
final class RatePlanController extends Controller
{
    public function __construct(private readonly RatePlanService $plans) {}

    public function show(string $unit): JsonResponse
    {
        return self::plan($this->plans->activePlan(Unit::query()->findOrFail($unit)));
    }

    public function update(Request $request, string $unit): JsonResponse
    {
        $plan = $this->plans->activePlan(Unit::query()->findOrFail($unit), lock: true);
        IfMatch::assert($request, $plan->version);

        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:200'],
            'base_nightly_cents' => ['sometimes', 'integer', 'between:1,10000000'],
        ]);

        return self::plan($this->plans->updatePlan($plan, $validated));
    }

    public function seasons(string $unit): JsonResponse
    {
        $plan = $this->plans->activePlan(Unit::query()->findOrFail($unit));
        $seasons = SeasonRule::query()->where('rate_plan_id', $plan->id)
            ->orderByRaw('lower(date_range)')->orderBy('priority')->orderBy('id')->get();

        return new JsonResponse([
            'data' => SeasonResource::collection($seasons)->resolve(),
            'warnings' => $this->plans->warningsForPlan($plan),
        ]);
    }

    public function storeSeason(Request $request, string $unit): JsonResponse
    {
        $plan = $this->plans->activePlan(Unit::query()->findOrFail($unit), lock: true);
        /** @var array{name: string, first_night: string, last_night: string, nightly_cents: int, priority?: int, min_nights?: int|null} $validated */
        $validated = $request->validate(self::seasonRules(creating: true));

        return $this->season($this->plans->createSeason($plan, $validated), 201);
    }

    public function updateSeason(Request $request, string $unit, string $season): JsonResponse
    {
        $model = $this->findSeason($unit, $season, lock: true);
        IfMatch::assert($request, $model->version);

        return $this->season($this->plans->updateSeason($model, $request->validate(self::seasonRules(creating: false))));
    }

    public function destroySeason(string $unit, string $season): Response
    {
        $this->plans->deleteSeason($this->findSeason($unit, $season, lock: true));

        return response()->noContent();
    }

    /**
     * @return array<string, list<string>>
     */
    private static function seasonRules(bool $creating): array
    {
        $required = $creating ? 'required' : 'sometimes';

        return [
            'name' => [$required, 'string', 'max:100'],
            'first_night' => [$required, 'date_format:Y-m-d', 'after_or_equal:2000-01-01', 'before:2100-01-01'],
            'last_night' => [$required, 'date_format:Y-m-d', 'before:2100-01-01', ...($creating ? ['after_or_equal:first_night'] : [])],
            'nightly_cents' => [$required, 'integer', 'between:1,10000000'],
            'priority' => ['sometimes', 'integer', 'between:0,1000'],
            'min_nights' => ['sometimes', 'nullable', 'integer', 'between:1,365'],
        ];
    }

    private function findSeason(string $unit, string $season, bool $lock = false): SeasonRule
    {
        $plan = $this->plans->activePlan(Unit::query()->findOrFail($unit));
        $query = SeasonRule::query()->where('rate_plan_id', $plan->id);

        return ($lock ? $query->lockForUpdate() : $query)->findOrFail($season);
    }

    private function season(SeasonRule $season, int $status = 200): JsonResponse
    {
        $body = (new SeasonResource($season))->resolve();
        $body['warnings'] = $this->plans->warningsFor($season);

        return (new JsonResponse($body, $status))->header('ETag', IfMatch::etag($season->version));
    }

    private static function plan(RatePlan $plan): JsonResponse
    {
        return (new RatePlanResource($plan))->response()->header('ETag', IfMatch::etag($plan->version));
    }
}
