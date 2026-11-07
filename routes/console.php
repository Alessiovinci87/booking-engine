<?php

use App\Domain\Reservations\Jobs\DispatchOccupancyReleaseJob;
use App\Domain\Tenancy\Jobs\DispatchOutboxJob;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Consegna dei messaggi in uscita (02, 03): dispatcher ogni minuto, un job per messaggio.
Schedule::job(new DispatchOutboxJob)->everyMinute()->withoutOverlapping()->name('outbox:dispatch');

// Rilascio delle occupazioni scadute (05, 03 T5/T6/T11/T16): dispatcher ogni minuto, un job per tenant.
Schedule::job(new DispatchOccupancyReleaseJob)->everyMinute()->withoutOverlapping()->name('occupancies:release');
