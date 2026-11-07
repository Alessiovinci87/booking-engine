<?php

namespace App\Domain\Calendar;

use App\Domain\Calendar\Enums\ChannelSource;
use App\Domain\Calendar\Enums\ExternalCalendarStatus;
use App\Domain\Calendar\Enums\IcsFeedStatus;
use App\Domain\Calendar\Models\ExternalCalendar;
use App\Domain\Calendar\Models\IcsFeed;
use App\Domain\Inventory\Models\Unit;
use App\Domain\Inventory\UnitService;
use App\Domain\Tenancy\Exceptions\BusinessRuleViolation;

/**
 * Checklist di collegamento dei calendari (06 "Topologia richiesta: a stella"; 5.6). Per ogni canale
 * usato dall'unità (Booking, Airbnb, altro):
 *
 * 1. `{canale}.feed_created` (automatico): esiste il feed ACTIVE dedicato a quel canale;
 * 2. `{canale}.feed_linked` (conferma del gestore): il feed dedicato è collegato sull'OTA, non un feed
 *    generico; come indizio si mostra l'ultima lettura dell'OTA;
 * 3. `{canale}.calendar_imported` (automatico, non per "altro"): il calendario di export dell'OTA è
 *    importato e sincronizzato almeno una volta;
 *
 * e, con almeno due OTA, `direct_links_removed` (conferma del gestore): rimossi i collegamenti iCal
 * diretti tra le OTA.
 */
class CalendarChecklist
{
    public function __construct(private readonly UnitService $units) {}

    /**
     * @return array{steps: list<array{key: string, channel: string|null, kind: string, done: bool, confirmed_at: string|null, evidence: array<string, mixed>}>, complete: bool}
     */
    public function for(Unit $unit): array
    {
        $calendars = ExternalCalendar::query()->where('unit_id', $unit->id)->where('status', '<>', ExternalCalendarStatus::DISABLED->value)->get();
        $feeds = IcsFeed::query()->where('unit_id', $unit->id)->where('status', IcsFeedStatus::ACTIVE->value)->get();
        $confirmed = $unit->calendar_checklist;

        $channels = array_values(array_filter(ChannelSource::cases(), fn (ChannelSource $c): bool => $calendars->contains(fn ($cal) => $cal->source === $c)
            || $feeds->contains(fn ($f) => $f->destination === $c)));

        $steps = [];

        foreach ($channels as $channel) {
            $feed = $feeds->first(fn (IcsFeed $f) => $f->destination === $channel);
            $calendar = $calendars->first(fn (ExternalCalendar $c) => $c->source === $channel);

            $steps[] = self::step($channel->value.'.feed_created', $channel, 'auto', $feed !== null, null);
            $steps[] = self::step($channel->value.'.feed_linked', $channel, 'manual', isset($confirmed[$channel->value.'.feed_linked']),
                $confirmed[$channel->value.'.feed_linked']['confirmed_at'] ?? null, ['last_fetched_at' => $feed?->last_fetched_at?->toIso8601ZuluString()]);

            if ($channel !== ChannelSource::OTHER) {
                $steps[] = self::step($channel->value.'.calendar_imported', $channel, 'auto', $calendar?->last_success_at !== null, null,
                    ['last_success_at' => $calendar?->last_success_at?->toIso8601ZuluString()]);
            }
        }

        $otas = array_filter($channels, fn (ChannelSource $c): bool => $c !== ChannelSource::OTHER);

        if (count($otas) >= 2) {
            $steps[] = self::step('direct_links_removed', null, 'manual', isset($confirmed['direct_links_removed']), $confirmed['direct_links_removed']['confirmed_at'] ?? null);
        }

        return ['steps' => $steps, 'complete' => $steps !== [] && ! in_array(false, array_column($steps, 'done'), true)];
    }

    /**
     * Conferma esplicita di un passo manuale presente nella checklist; altrimenti 422.
     *
     * @return array{steps: list<array{key: string, channel: string|null, kind: string, done: bool, confirmed_at: string|null, evidence: array<string, mixed>}>, complete: bool}
     */
    public function confirm(Unit $unit, string $step, ?string $userId): array
    {
        $manual = array_column(array_filter($this->for($unit)['steps'], fn (array $s): bool => $s['kind'] === 'manual'), 'key');

        if (! in_array($step, $manual, true)) {
            throw BusinessRuleViolation::make(422, 'CHECKLIST_STEP_INVALID', ['step' => $step, 'allowed' => $manual]);
        }

        return $this->for($this->units->confirmChecklistStep($unit, $step, $userId));
    }

    /**
     * @param  array<string, mixed>  $evidence
     * @return array{key: string, channel: string|null, kind: string, done: bool, confirmed_at: string|null, evidence: array<string, mixed>}
     */
    private static function step(string $key, ?ChannelSource $channel, string $kind, bool $done, ?string $confirmedAt, array $evidence = []): array
    {
        return ['key' => $key, 'channel' => $channel?->value, 'kind' => $kind, 'done' => $done, 'confirmed_at' => $confirmedAt, 'evidence' => $evidence];
    }
}
