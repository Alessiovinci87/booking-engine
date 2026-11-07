<?php

namespace App\Domain\Calendar\StateMachines;

use App\Domain\Calendar\Enums\ChannelSource;
use App\Domain\Calendar\Enums\IcsFeedStatus as S;
use App\Domain\Calendar\Models\IcsFeed;
use App\Domain\Reservations\Exceptions\IllegalTransition;
use App\Domain\Tenancy\Audit\AuditLogger;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/**
 * Stati di un feed iCal esportato (06 "Ciclo di vita dei feed", I19):
 *
 * | Da | Evento | A |
 * |---|---|---|
 * | — | creazione | ACTIVE |
 * | ACTIVE | rotazione del token, offboarding | FROZEN (`frozen_at`) |
 * | FROZEN | 7 giorni dopo la rotazione, conferma del gestore, condizioni dell'offboarding | RETIRED |
 * | ACTIVE | conferma esplicita del gestore che l'OTA non usa più il feed | RETIRED |
 *
 * RETIRED è definitivo: il feed risponde 410. Nell'audit mai il token.
 */
final class IcsFeedStateMachine
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function create(string $unitId, ChannelSource $destination, ?string $pairedCalendarId, string $token, string $tokenHash): IcsFeed
    {
        $feed = new IcsFeed;
        $feed->id = (string) Str::uuid7();
        $feed->forceFill([
            'unit_id' => $unitId,
            'destination' => $destination,
            'paired_external_calendar_id' => $pairedCalendarId,
            'token_encrypted' => $token,
            'token_hash' => $tokenHash,
            'status' => S::ACTIVE,
        ])->save();

        $this->audit->record('ics_feed.created', 'ics_feed', $feed->id, [
            'unit_id' => $unitId, 'destination' => $destination->value, 'paired_external_calendar_id' => $pairedCalendarId,
        ]);

        return $feed;
    }

    public function freeze(IcsFeed $feed, string $reason): void
    {
        if ($feed->status !== S::ACTIVE) {
            throw IllegalTransition::of('ics_feed', $feed->status->value, 'freeze');
        }

        $now = CarbonImmutable::now();
        $feed->frozen_at = $now;

        if ($reason === 'rotation') {
            $feed->rotated_at = $now;
        }

        $this->move($feed, S::FROZEN, ['reason' => $reason]);
    }

    public function retire(IcsFeed $feed, string $reason): void
    {
        if ($feed->status === S::RETIRED) {
            throw IllegalTransition::of('ics_feed', $feed->status->value, 'retire');
        }

        $this->move($feed, S::RETIRED, ['reason' => $reason]);
    }

    /** @param  array<string, mixed>  $extra */
    private function move(IcsFeed $feed, S $to, array $extra): void
    {
        $from = $feed->status;
        $feed->status = $to;
        $feed->save();

        $this->audit->record('ics_feed.status_changed', 'ics_feed', $feed->id, ['from' => $from->value, 'to' => $to->value, ...$extra]);
    }
}
