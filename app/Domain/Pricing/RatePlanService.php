<?php

namespace App\Domain\Pricing;

use App\Domain\Inventory\Models\Unit;
use App\Domain\Pricing\Models\RatePlan;
use App\Domain\Pricing\Models\SeasonRule;
use App\Domain\Reservations\StayRange;
use App\Domain\Tenancy\Audit\AuditLogger;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Tariffe dell'unità (04; 11, attività 2.3): prezzo base del piano attivo e stagioni.
 *
 * Le stagioni sovrapposte con la stessa priorità sono consentite (04 le risolve con la durata più
 * breve, poi con la più recente), ma il gestore riceve un avviso con le stagioni coinvolte.
 */
final class RatePlanService
{
    public const OVERLAP_WARNING = 'SEASON_OVERLAP_SAME_PRIORITY';

    public function __construct(private readonly AuditLogger $audit) {}

    /** Piano attivo dell'unità (uno solo per unità, indice parziale nel database). */
    public function activePlan(Unit $unit, bool $lock = false): RatePlan
    {
        $query = RatePlan::query()->where('unit_id', $unit->id)->where('active', true);

        return ($lock ? $query->lockForUpdate() : $query)->firstOrFail();
    }

    /**
     * @param  array<string, mixed>  $attributes  name, base_nightly_cents
     */
    public function updatePlan(RatePlan $plan, array $attributes): RatePlan
    {
        $plan->fill($attributes);

        if ($plan->isDirty()) {
            $plan->save();
            $this->audit->recordModelChange('rate_plan.updated', $plan);
        }

        return $plan->refresh();
    }

    /**
     * @param  array{name: string, first_night: string, last_night: string, nightly_cents: int, priority?: int, min_nights?: int|null}  $data
     */
    public function createSeason(RatePlan $plan, array $data): SeasonRule
    {
        $season = new SeasonRule;
        $season->rate_plan_id = $plan->id;
        $season->fill(self::seasonAttributes($data))->save();
        $this->audit->recordModelChange('season_rule.created', $season, ['unit_id' => $plan->unit_id]);

        return $season->refresh();
    }

    /**
     * @param  array<string, mixed>  $data  campi parziali
     */
    public function updateSeason(SeasonRule $season, array $data): SeasonRule
    {
        $current = $season->date_range;
        $data += [
            'first_night' => $current->checkIn->toDateString(),
            'last_night' => $current->checkOut->subDay()->toDateString(),
        ];

        $season->fill(self::seasonAttributes($data));

        if ($season->isDirty()) {
            $season->save();
            $this->audit->recordModelChange('season_rule.updated', $season);
        }

        return $season->refresh();
    }

    public function deleteSeason(SeasonRule $season): void
    {
        $season->delete();
        $this->audit->record('season_rule.deleted', 'season_rule', $season->id, ['rate_plan_id' => $season->rate_plan_id]);
    }

    /**
     * Stagioni dello stesso piano, con la stessa priorità, che condividono almeno una notte.
     *
     * @return Collection<int, SeasonRule>
     */
    public function overlapping(SeasonRule $season): Collection
    {
        return SeasonRule::query()
            ->where('rate_plan_id', $season->rate_plan_id)
            ->where('priority', $season->priority)
            ->whereKeyNot($season->id)
            ->whereRaw('date_range && ?::daterange', [$season->date_range->toPostgres()])
            ->orderByRaw('lower(date_range)')
            ->get();
    }

    /**
     * Avvisi per una stagione appena salvata: [] se non ci sono sovrapposizioni a parità di priorità.
     *
     * @return list<array<string, mixed>>
     */
    public function warningsFor(SeasonRule $season): array
    {
        $others = $this->overlapping($season);

        return $others->isEmpty() ? [] : [self::warning($season->priority, [$season, ...$others->all()])];
    }

    /**
     * Avvisi per tutte le stagioni del piano: uno per ogni coppia sovrapposta con la stessa priorità.
     *
     * @return list<array<string, mixed>>
     */
    public function warningsForPlan(RatePlan $plan): array
    {
        $seasons = SeasonRule::query()->where('rate_plan_id', $plan->id)
            ->orderByRaw('lower(date_range)')->orderBy('id')->get()->values();
        $warnings = [];

        foreach ($seasons as $i => $first) {
            foreach ($seasons->slice($i + 1) as $second) {
                if ($first->priority === $second->priority && $first->date_range->overlaps($second->date_range)) {
                    $warnings[] = self::warning($first->priority, [$first, $second]);
                }
            }
        }

        return $warnings;
    }

    /**
     * Notti nell'API come prima e ultima notte comprese; nel database come [prima, ultima + 1).
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private static function seasonAttributes(array $data): array
    {
        $attributes = array_intersect_key($data, array_flip(['name', 'nightly_cents', 'priority', 'min_nights']));
        $first = $data['first_night'] ?? null;
        $last = $data['last_night'] ?? null;

        $lastNight = is_string($last) ? CarbonImmutable::createFromFormat('!Y-m-d', $last, 'UTC') : null;

        if (is_string($first) && $lastNight instanceof CarbonImmutable) {
            if ($lastNight->toDateString() < $first) {
                throw ValidationException::withMessages(['last_night' => __('pricing.validation.last_before_first')]);
            }

            $attributes['date_range'] = StayRange::of($first, $lastNight->addDay()->toDateString());
        }

        return $attributes;
    }

    /**
     * @param  list<SeasonRule>  $seasons
     * @return array<string, mixed>
     */
    private static function warning(int $priority, array $seasons): array
    {
        return [
            'code' => self::OVERLAP_WARNING,
            'priority' => $priority,
            'seasons' => array_map(fn (SeasonRule $s): array => [
                'id' => $s->id,
                'name' => $s->name,
                'first_night' => $s->date_range->checkIn->toDateString(),
                'last_night' => $s->date_range->checkOut->subDay()->toDateString(),
            ], $seasons),
        ];
    }
}
