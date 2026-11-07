<?php

namespace App\Domain\Calendar;

use App\Domain\Calendar\Enums\ExternalCalendarStatus;
use App\Domain\Calendar\Enums\ExternalEventStatus;
use App\Domain\Calendar\Enums\SyncRunOutcome;
use App\Domain\Calendar\Http\FetchFailed;
use App\Domain\Calendar\Http\FetchResponse;
use App\Domain\Calendar\Import\IcsParseFailed;
use App\Domain\Calendar\Import\IcsParser;
use App\Domain\Calendar\Import\ParsedCalendar;
use App\Domain\Calendar\Models\ExternalCalendar;
use App\Domain\Calendar\Models\ExternalCalendarEvent;
use App\Domain\Calendar\Models\SyncRun;
use App\Domain\Calendar\StateMachines\ExternalCalendarStateMachine;
use App\Domain\Calendar\StateMachines\ExternalEventStateMachine;
use App\Domain\Inventory\Models\Unit;
use App\Domain\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Import di un calendario esterno (06 "Import"; 5.2).
 *
 * 1. Il download (SafeHttpFetcher, richieste condizionali con ETag e Last-Modified) avviene fuori dalla
 *    transazione.
 * 2. Errore, timeout, URL bloccato, HTTP diverso da 200/304, ICS non valido → SyncRun ERROR, nessuna
 *    modifica agli eventi; nuovo tentativo con backoff 10, 20, 40, 60 minuti, o più tardi se l'OTA
 *    chiede `Retry-After`.
 * 3. `304` → NOT_MODIFIED.
 * 4. Risposta valida → in una transazione con la riga del calendario bloccata: eventi nuovi, modificati
 *    (audit), ricomparsi; assenti in due passi (PENDING_REMOVAL, poi REMOVED ad almeno 10 minuti);
 *    salvaguardia anti-svuotamento → SUSPICIOUS senza rimozioni e allerta. Classificazione degli echi
 *    (EchoClassifier) e conflitti (ConflictDetector) per gli eventi nuovi, modificati o ricomparsi;
 *    i conflitti aperti di un evento rimosso si risolvono da soli (ConflictService).
 *
 * Prossimo giro dopo 10 minuti ± 90 secondi. Nei log solo ID: l'URL può contenere il token dell'OTA.
 */
class IcalImportService
{
    public const POLL_MINUTES = 10;

    public const JITTER_SECONDS = 90;

    public const MAX_BACKOFF_MINUTES = 60;

    /** Un Retry-After oltre questo limite si tronca: il calendario non resta fermo per giorni. */
    public const MAX_RETRY_AFTER_SECONDS = 6 * 3600;

    public function __construct(
        private readonly TenantContext $tenants,
        private readonly SafeHttpFetcher $fetcher,
        private readonly IcsParser $parser,
        private readonly EchoClassifier $echoes,
        private readonly ExternalEventStateMachine $events,
        private readonly ExternalCalendarStateMachine $calendars,
        private readonly ConflictDetector $detector,
        private readonly ConflictService $conflicts,
        private readonly SyncHealthService $health,
    ) {}

    /**
     * @return SyncRun|null null se il calendario non esiste o è disattivato
     */
    public function sync(string $tenantId, string $calendarId): ?SyncRun
    {
        $started = CarbonImmutable::now();

        $prepared = $this->tenants->run($tenantId, function () use ($calendarId, $started): ?array {
            $calendar = ExternalCalendar::query()->find($calendarId);

            // Un calendario dimostrativo non si scarica mai (ADR-0013 punto 44).
            if ($calendar === null || $calendar->status === ExternalCalendarStatus::DISABLED || $calendar->demo) {
                return null;
            }

            $timezone = Unit::query()->with('property')->findOrFail($calendar->unit_id)->property->timezone;

            $run = new SyncRun;
            $run->id = (string) Str::uuid7();
            $run->forceFill(['external_calendar_id' => $calendar->id, 'started_at' => $started])->save();

            return [$calendar, $run, $timezone];
        });

        if ($prepared === null) {
            return null;
        }

        /** @var ExternalCalendar $calendar */
        /** @var SyncRun $run */
        [$calendar, $run, $timezone] = $prepared;

        $headers = array_filter([
            'If-None-Match' => $calendar->etag ?? '',
            'If-Modified-Since' => $calendar->last_modified ?? '',
        ]);

        try {
            $response = $this->fetcher->get($calendar->url_encrypted, $headers);
        } catch (FetchFailed $e) {
            return $this->failed($tenantId, $calendar->id, $run, $started, $e->error->value, null, null);
        }

        if ($response->isNotModified()) {
            return $this->notModified($tenantId, $calendar->id, $run, $started, $response);
        }

        if (! $response->isSuccessful()) {
            return $this->failed($tenantId, $calendar->id, $run, $started, 'HTTP_'.$response->status, $response->status, $response->retryAfterSeconds);
        }

        try {
            $parsed = $this->parser->parse($response->body, $timezone);
        } catch (IcsParseFailed $e) {
            return $this->failed($tenantId, $calendar->id, $run, $started, $e->errorCode, $response->status, null);
        }

        return $this->apply($tenantId, $calendar->id, $run, $started, $response, $parsed, $timezone);
    }

    /** Backoff dopo n errori consecutivi: 10, 20, 40, poi 60 minuti. */
    public static function backoffMinutes(int $failures): int
    {
        return (int) min(self::POLL_MINUTES * 2 ** max(0, $failures - 1), self::MAX_BACKOFF_MINUTES);
    }

    public static function nextPoll(CarbonImmutable $now, int $minutes, int $atLeastSeconds = 0): CarbonImmutable
    {
        $seconds = max($minutes * 60 + random_int(-self::JITTER_SECONDS, self::JITTER_SECONDS), $atLeastSeconds);

        return $now->addSeconds($seconds);
    }

    private function failed(string $tenantId, string $calendarId, SyncRun $run, CarbonImmutable $started, string $code, ?int $httpStatus, ?int $retryAfter): SyncRun
    {
        return $this->tenants->run($tenantId, function () use ($tenantId, $calendarId, $run, $started, $code, $httpStatus, $retryAfter): SyncRun {
            $calendar = ExternalCalendar::query()->lockForUpdate()->findOrFail($calendarId);
            $now = CarbonImmutable::now();

            $calendar->consecutive_failures++;
            $calendar->last_attempt_at = $now;
            $calendar->next_poll_at = self::nextPoll(
                $now,
                self::backoffMinutes($calendar->consecutive_failures),
                min($retryAfter ?? 0, self::MAX_RETRY_AFTER_SECONDS),
            );
            $calendar->save();

            Log::warning('calendar.sync_failed', [
                'tenant_id' => $tenantId, 'external_calendar_id' => $calendar->id, 'error_code' => $code,
                'consecutive_failures' => $calendar->consecutive_failures,
            ]);

            return $this->finish($run, $started, SyncRunOutcome::ERROR, $httpStatus, $code);
        });
    }

    private function notModified(string $tenantId, string $calendarId, SyncRun $run, CarbonImmutable $started, FetchResponse $response): SyncRun
    {
        return $this->tenants->run($tenantId, function () use ($calendarId, $run, $started, $response): SyncRun {
            $calendar = ExternalCalendar::query()->lockForUpdate()->findOrFail($calendarId);
            $this->succeeded($calendar, $response);

            return $this->finish($run, $started, SyncRunOutcome::NOT_MODIFIED, $response->status, null);
        });
    }

    private function apply(string $tenantId, string $calendarId, SyncRun $run, CarbonImmutable $started, FetchResponse $response, ParsedCalendar $parsed, string $timezone): SyncRun
    {
        return $this->tenants->run($tenantId, function () use ($tenantId, $calendarId, $run, $started, $response, $parsed, $timezone): SyncRun {
            $calendar = ExternalCalendar::query()->lockForUpdate()->findOrFail($calendarId);
            $now = CarbonImmutable::now();
            $today = CarbonImmutable::parse(CarbonImmutable::now($timezone)->toDateString(), 'UTC');

            /** @var array<string, ExternalCalendarEvent> $existing */
            $existing = ExternalCalendarEvent::query()
                ->where('external_calendar_id', $calendar->id)
                ->get()->keyBy('uid')->all();

            // Dopo un cambio di URL gli eventi visti solo con il vecchio URL restano finché il nuovo non li
            // conferma o il gestore li rimuove (06 "Rimozione"): fuori da rimozione e salvaguardia.
            $keptAfterUrlChange = fn (ExternalCalendarEvent $e): bool => $calendar->url_changed_at !== null && $e->last_seen_at->lessThan($calendar->url_changed_at);
            $isLive = fn (ExternalCalendarEvent $e): bool => $e->status !== ExternalEventStatus::REMOVED && $e->stay->checkOut->greaterThan($today) && ! $keptAfterUrlChange($e);
            $liveBefore = array_filter($existing, $isLive);

            $incoming = [];
            foreach ($parsed->events as $event) {
                $incoming[$event->uid] = true;
            }

            $absent = array_filter($liveBefore, fn (ExternalCalendarEvent $e): bool => ! isset($incoming[$e->uid]));
            $suspicious = self::suspicious(count($liveBefore), count($parsed->events), count($absent));

            $added = 0;
            $updated = 0;

            foreach ($parsed->events as $event) {
                $current = $existing[$event->uid] ?? null;

                if ($current === null) {
                    [$echo, $echoOf] = $this->echoes->classify($calendar, $event);
                    $this->detector->detect($calendar, $this->events->create($calendar, $event, $echo, $echoOf, $now));
                    $added++;

                    continue;
                }

                if ($this->events->seen($current, $event, $now)) {
                    $this->detector->detect($calendar, $current);
                    $updated++;
                }
            }

            $pending = 0;
            $removed = 0;

            if (! $suspicious) {
                foreach ($absent as $event) {
                    if ($event->status === ExternalEventStatus::ACTIVE) {
                        $this->events->markPendingRemoval($event, $now);
                        $pending++;
                    } elseif ($this->events->confirmRemoval($event, $now)) {
                        $this->conflicts->eventRemoved($event);
                        $removed++;
                    }
                }
            }

            $run->forceFill([
                'events_seen' => count($parsed->events),
                'events_added' => $added,
                'events_updated' => $updated,
                'events_pending_removal' => $pending,
                'events_removed' => $removed,
            ]);

            if ($parsed->recurringSkipped > 0 || $parsed->invalidSkipped > 0) {
                Log::warning('calendar.events_skipped', [
                    'tenant_id' => $tenantId, 'external_calendar_id' => $calendar->id,
                    'recurring' => $parsed->recurringSkipped, 'invalid' => $parsed->invalidSkipped,
                ]);
            }

            if ($suspicious) {
                // Nessuna rimozione e allerta (06 punto 5). Il calendario non conta come letto con
                // successo: se la risposta resta sospetta, dopo 3 ore diventa UNHEALTHY e il gestore
                // riceve l'email (ADR-0013).
                $calendar->last_attempt_at = $now;
                $calendar->etag = null;
                $calendar->last_modified = null;
                $calendar->next_poll_at = self::nextPoll($now, self::POLL_MINUTES);
                $calendar->save();

                Log::error('calendar.sync_suspicious', [
                    'tenant_id' => $tenantId, 'external_calendar_id' => $calendar->id,
                    'future_events_before' => count($liveBefore), 'future_events_now' => count($parsed->events),
                ]);

                return $this->finish($run, $started, SyncRunOutcome::SUSPICIOUS, $response->status, null);
            }

            $this->succeeded($calendar, $response);

            return $this->finish($run, $started, SyncRunOutcome::SUCCESS, $response->status, null);
        });
    }

    /**
     * Salvaguardia anti-svuotamento (06 punto 5): zero eventi futuri quando prima erano almeno 2,
     * oppure perdita di oltre il 50% degli eventi futuri. La regola del 50% vale da 2 eventi in su:
     * con un solo evento futuro la sua scomparsa è una normale cancellazione (ADR-0013).
     */
    public static function suspicious(int $futureBefore, int $futureNow, int $lost): bool
    {
        if ($futureBefore < 2) {
            return false;
        }

        return $futureNow === 0 || $lost * 2 > $futureBefore;
    }

    private function succeeded(ExternalCalendar $calendar, FetchResponse $response): void
    {
        $now = CarbonImmutable::now();

        $calendar->etag = $response->etag ?? $calendar->etag;
        $calendar->last_modified = $response->lastModified ?? $calendar->last_modified;
        $calendar->last_attempt_at = $now;
        $calendar->last_success_at = $now;
        $calendar->consecutive_failures = 0;
        $calendar->next_poll_at = self::nextPoll($now, self::POLL_MINUTES);
        $calendar->save();

        $this->calendars->setHealth($calendar, ExternalCalendarStatus::ACTIVE);
        $this->health->refreshUnit($calendar->unit_id);
    }

    private function finish(SyncRun $run, CarbonImmutable $started, SyncRunOutcome $outcome, ?int $httpStatus, ?string $errorCode): SyncRun
    {
        $finished = CarbonImmutable::now();

        $run->forceFill([
            'finished_at' => $finished,
            'outcome' => $outcome,
            'http_status' => $httpStatus,
            'error_code' => $errorCode,
            'duration_ms' => (int) $started->diffInMilliseconds($finished, true),
        ])->save();

        return $run;
    }
}
