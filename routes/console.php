<?php

use App\Domain\Calendar\Jobs\DispatchCalendarSyncJob;
use App\Domain\Calendar\Jobs\DispatchFeedRetirementJob;
use App\Domain\Calendar\Jobs\DispatchSyncHealthJob;
use App\Domain\Communications\Alerts\Jobs\CheckQueueDelayJob;
use App\Domain\Communications\Alerts\Jobs\UnhealthyCalendarsSummaryJob;
use App\Domain\Payments\Jobs\DispatchPaymentReconciliationJob;
use App\Domain\Payments\Jobs\ReconcilePaymentsJob;
use App\Domain\Privacy\Jobs\DispatchRetentionJob;
use App\Domain\Quotes\Jobs\DispatchQuoteExpiryJob;
use App\Domain\Reservations\Jobs\DispatchOccupancyReleaseJob;
use App\Domain\Tenancy\Jobs\DispatchOutboxJob;
use App\Domain\Tenancy\Offboarding\Jobs\DispatchOffboardingChecksJob;
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

// Riconciliazione dei pagamenti (05): giro completo ogni 15 minuti (con il controllo di I15), CAPTURING
// ogni 5 minuti. Dispatcher con app_admin_ops, un job per tenant con app_runtime.
Schedule::job(new DispatchPaymentReconciliationJob(ReconcilePaymentsJob::FULL))->everyFifteenMinutes()->withoutOverlapping()->name('payments:reconcile');
Schedule::job(new DispatchPaymentReconciliationJob(ReconcilePaymentsJob::CAPTURING))->everyFiveMinutes()->withoutOverlapping()->name('payments:reconcile-capturing');

// Sincronizzazione dei calendari importati (06 "Polling"): dispatcher ogni minuto sui calendari con
// next_poll_at scaduto, un job per calendario.
Schedule::job(new DispatchCalendarSyncJob)->everyMinute()->withoutOverlapping()->name('calendars:sync');

// Ritiro dei feed iCal ruotati da oltre 7 giorni (06): dispatcher orario, un job per tenant.
Schedule::job(new DispatchFeedRetirementJob)->hourly()->withoutOverlapping()->name('ical:retire-rotated');

// Salute della sincronizzazione dei calendari importati (06): dispatcher ogni 5 minuti, un job per tenant.
Schedule::job(new DispatchSyncHealthJob)->everyFiveMinutes()->withoutOverlapping()->name('calendars:health');

// Allerte operative (08, 6.5): coda in ritardo ogni 5 minuti; riepilogo interno dei calendari
// UNHEALTHY ogni mattina alle 7 (ora italiana).
Schedule::job(new CheckQueueDelayJob)->everyFiveMinutes()->withoutOverlapping()->name('alerts:queue-delay');
Schedule::job(new UnhealthyCalendarsSummaryJob)->dailyAt('07:00')->timezone('Europe/Rome')->name('alerts:calendars-daily');

// Scadenza dei preventivi (03, sezione 4): dispatcher ogni 5 minuti, un job per tenant.
Schedule::job(new DispatchQuoteExpiryJob)->everyFiveMinutes()->withoutOverlapping()->name('quotes:expire');

// Uscita del cliente (06, 09, 7.1): ogni notte export scaduti cancellati e ritiro automatico dei feed non
// più letti dopo 30 giorni dall'avvio. Dispatcher con app_admin_ops, un job per tenant.
Schedule::job(new DispatchOffboardingChecksJob)->dailyAt('03:30')->timezone('Europe/Rome')->withoutOverlapping()->name('offboarding:checks');

// Conservazione dei dati (09, 7.2): ogni notte categorie globali e un job per tenant; le categorie non
// validate dal professionista girano in simulazione (solo conteggi nell'audit).
Schedule::job(new DispatchRetentionJob)->dailyAt('02:30')->timezone('Europe/Rome')->withoutOverlapping()->name('retention:nightly');
