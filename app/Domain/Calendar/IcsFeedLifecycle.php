<?php

namespace App\Domain\Calendar;

use App\Domain\Calendar\Enums\ChannelSource;
use App\Domain\Calendar\Enums\ExternalCalendarStatus;
use App\Domain\Calendar\Enums\IcsFeedStatus;
use App\Domain\Calendar\Export\FeedDocument;
use App\Domain\Calendar\Export\IcsFeedBuilder;
use App\Domain\Calendar\Models\ExternalCalendar;
use App\Domain\Calendar\Models\IcsFeed;
use App\Domain\Calendar\StateMachines\IcsFeedStateMachine;
use App\Domain\Inventory\Models\Unit;
use App\Domain\Tenancy\Exceptions\BusinessRuleViolation;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Ciclo di vita dei feed iCal esportati (06 "Ciclo di vita dei feed", 08 "Token"; 5.5):
 *
 * - token casuale di 32 byte (base64url), cifrato a riposo per mostrare l'URL al gestore, con hash
 *   SHA-256 per la ricerca (funzione SECURITY DEFINER app_tenant_by_ics_feed);
 * - un solo feed ACTIVE per unità e destinazione, accoppiato al calendario importato dallo stesso canale;
 * - rotazione: il vecchio feed resta FROZEN per 7 giorni, poi RETIRED; il nuovo è ACTIVE;
 * - lettura: RETIRED → 410 FEED_RETIRED; FROZEN mai vuoto (I19); ultima lettura e user agent
 *   registrati al massimo ogni 5 minuti.
 */
class IcsFeedLifecycle
{
    public const ROTATION_GRACE_DAYS = 7;

    public const READ_RECORD_MINUTES = 5;

    public const STALE_READ_HOURS = 24;

    public function __construct(
        private readonly IcsFeedStateMachine $machine,
        private readonly IcsFeedBuilder $builder,
    ) {}

    public static function newToken(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }

    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    public static function url(IcsFeed $feed): string
    {
        return rtrim((string) config('app.url'), '/').'/ical/'.$feed->token_encrypted.'.ics';
    }

    /** Feed per una destinazione; 409 se esiste già un feed ACTIVE per quell'unità e destinazione. */
    public function create(Unit $unit, ChannelSource $destination): IcsFeed
    {
        return DB::transaction(function () use ($unit, $destination): IcsFeed {
            Unit::query()->lockForUpdate()->findOrFail($unit->id);

            $exists = IcsFeed::query()->where('unit_id', $unit->id)->where('destination', $destination->value)
                ->where('status', IcsFeedStatus::ACTIVE->value)->exists();

            if ($exists) {
                throw BusinessRuleViolation::make(409, 'ICS_FEED_EXISTS', ['destination' => $destination->value]);
            }

            $token = self::newToken();

            return $this->machine->create($unit->id, $destination, $this->pairedCalendarId($unit->id, $destination), $token, self::hashToken($token));
        });
    }

    /** Nuovo token: il feed attuale diventa FROZEN per 7 giorni (06), il nuovo è ACTIVE. */
    public function rotate(IcsFeed $feed): IcsFeed
    {
        return DB::transaction(function () use ($feed): IcsFeed {
            $current = IcsFeed::query()->lockForUpdate()->findOrFail($feed->id);

            if ($current->status !== IcsFeedStatus::ACTIVE) {
                throw BusinessRuleViolation::make(409, 'ILLEGAL_TRANSITION', ['machine' => 'ics_feed', 'from' => $current->status->value, 'event' => 'rotate']);
            }

            $this->machine->freeze($current, 'rotation');
            $token = self::newToken();

            return $this->machine->create($current->unit_id, $current->destination, $current->paired_external_calendar_id, $token, self::hashToken($token));
        });
    }

    /** Feed FROZEN per rotazione da oltre 7 giorni → RETIRED (job orario). */
    public function retireExpiredRotations(CarbonImmutable $now): int
    {
        $feeds = IcsFeed::query()
            ->where('status', IcsFeedStatus::FROZEN->value)
            ->whereNotNull('rotated_at')
            ->where('rotated_at', '<=', $now->subDays(self::ROTATION_GRACE_DAYS))
            ->lockForUpdate()->get();

        foreach ($feeds as $feed) {
            $this->machine->retire($feed, 'rotation_grace_expired');
        }

        return $feeds->count();
    }

    /** Un calendario importato appena creato si accoppia al feed della stessa OTA che ne è privo. */
    public function pairWithCalendar(ExternalCalendar $calendar): void
    {
        IcsFeed::query()
            ->where('unit_id', $calendar->unit_id)
            ->where('destination', $calendar->source->value)
            ->whereIn('status', [IcsFeedStatus::ACTIVE->value, IcsFeedStatus::FROZEN->value])
            ->whereNull('paired_external_calendar_id')
            ->get()
            ->each(function (IcsFeed $feed) use ($calendar): void {
                $feed->paired_external_calendar_id = $calendar->id;
                $feed->save();
            });
    }

    /**
     * Documento da servire per una lettura dell'OTA (dentro il contesto del tenant del feed).
     *
     * @throws BusinessRuleViolation 410 FEED_RETIRED
     * @throws Export\FeedUnavailable feed FROZEN senza elementi (I19)
     */
    public function serve(IcsFeed $feed, ?string $userAgent): FeedDocument
    {
        if ($feed->status === IcsFeedStatus::RETIRED) {
            throw BusinessRuleViolation::make(410, 'FEED_RETIRED');
        }

        $this->recordRead($feed, $userAgent);

        return $this->builder->build($feed);
    }

    /** Ultima lettura e user agent, al massimo ogni 5 minuti per non scrivere a ogni richiesta. */
    private function recordRead(IcsFeed $feed, ?string $userAgent): void
    {
        $now = CarbonImmutable::now();

        if ($feed->last_fetched_at !== null && $feed->last_fetched_at->greaterThan($now->subMinutes(self::READ_RECORD_MINUTES))) {
            return;
        }

        $feed->last_fetched_at = $now;
        $feed->last_fetch_user_agent = $userAgent === null ? null : mb_substr(preg_replace('/[\x00-\x1f\x7f]/', '', $userAgent) ?? '', 0, 255);
        $feed->save();
    }

    private function pairedCalendarId(string $unitId, ChannelSource $destination): ?string
    {
        $id = ExternalCalendar::query()
            ->where('unit_id', $unitId)
            ->where('source', $destination->value)
            ->where('status', '<>', ExternalCalendarStatus::DISABLED->value)
            ->orderBy('created_at')
            ->value('id');

        return $id === null ? null : (string) $id;
    }
}
