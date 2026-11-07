<?php

namespace App\Domain\Calendar\Jobs;

use App\Domain\Calendar\IcalImportService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;

/**
 * Sincronizzazione di un calendario importato (06 "Polling": un job per calendario). Unico in coda per
 * calendario e protetto da un lock (Redis in staging e produzione) contro due giri contemporanei.
 * Il download avviene fuori dalle transazioni, dentro IcalImportService.
 */
final class SyncExternalCalendarJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public const LOCK_SECONDS = 120;

    public int $tries = 1;

    public int $timeout = 90;

    public int $uniqueFor = 300;

    public function __construct(public readonly string $tenantId, public readonly string $calendarId) {}

    public function uniqueId(): string
    {
        return $this->calendarId;
    }

    public function handle(IcalImportService $import): void
    {
        Cache::lock('calendar-sync:'.$this->calendarId, self::LOCK_SECONDS)
            ->get(fn () => $import->sync($this->tenantId, $this->calendarId));
    }
}
