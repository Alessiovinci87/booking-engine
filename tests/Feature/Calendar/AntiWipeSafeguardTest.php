<?php

use App\Domain\Calendar\Enums\ExternalEventStatus;
use App\Domain\Calendar\Enums\SyncRunOutcome;
use App\Domain\Calendar\Http\RawResponse;
use App\Domain\Calendar\IcalImportService;
use Illuminate\Support\Facades\Log;

/*
 * 06 "Rimozione" e "Elaborazione" punto 5 (I6): rimozione in due passi ad almeno 10 minuti e
 * salvaguardia anti-svuotamento (zero eventi futuri quando prima erano almeno 2, o perdita di oltre il
 * 50%) → SUSPICIOUS, nessuna rimozione, allerta.
 */

function threeEvents(): array
{
    return [
        ['uid' => 'b1@booking.com', 'in' => futureDay(3), 'out' => futureDay(5)],
        ['uid' => 'b2@booking.com', 'in' => futureDay(8), 'out' => futureDay(9)],
        ['uid' => 'b3@booking.com', 'in' => futureDay(12), 'out' => futureDay(15)],
    ];
}

it('calendario improvvisamente vuoto → SUSPICIOUS, nessuna rimozione, allerta', function () {
    Log::spy();
    $fx = calendarFixture();
    $fx['http']->responses[$fx['url']] = icsResponse(otaIcs('booking', threeEvents()));
    syncCalendar($fx);
    $successBefore = freshCalendar($fx)->last_success_at;

    $this->travel(11)->minutes();
    $fx['http']->responses[$fx['url']] = icsResponse(otaIcs('booking', []));
    $run = syncCalendar($fx);

    expect($run->outcome)->toBe(SyncRunOutcome::SUSPICIOUS)
        ->and(array_map(fn ($e) => $e->status, calendarEvents($fx)))->each->toBe(ExternalEventStatus::ACTIVE)
        ->and(freshCalendar($fx)->last_success_at->equalTo($successBefore))->toBeTrue();

    Log::shouldHaveReceived('error')->withArgs(fn ($message) => $message === 'calendar.sync_suspicious')->once();
});

it('perdita di oltre il 50% degli eventi futuri → SUSPICIOUS', function () {
    $fx = calendarFixture();
    $fx['http']->responses[$fx['url']] = icsResponse(otaIcs('booking', threeEvents()));
    syncCalendar($fx);

    $fx['http']->responses[$fx['url']] = icsResponse(otaIcs('booking', [threeEvents()[0]]));
    $run = syncCalendar($fx);

    expect($run->outcome)->toBe(SyncRunOutcome::SUSPICIOUS)
        ->and(calendarEvents($fx)['b2@booking.com']->status)->toBe(ExternalEventStatus::ACTIVE);
});

it('rimozione in due passi: PENDING_REMOVAL, poi REMOVED solo dopo almeno 10 minuti', function () {
    $fx = calendarFixture();
    $fx['http']->responses[$fx['url']] = icsResponse(otaIcs('booking', threeEvents()));
    syncCalendar($fx);

    // b3 sparisce (1 su 3: non sospetto)
    $fx['http']->responses[$fx['url']] = icsResponse(otaIcs('booking', array_slice(threeEvents(), 0, 2)));
    $first = syncCalendar($fx);
    expect($first->outcome)->toBe(SyncRunOutcome::SUCCESS)
        ->and($first->events_pending_removal)->toBe(1)
        ->and(calendarEvents($fx)['b3@booking.com']->status)->toBe(ExternalEventStatus::PENDING_REMOVAL);

    // Seconda risposta dopo 5 minuti: ancora in attesa, le date restano bloccate
    $this->travel(5)->minutes();
    syncCalendar($fx);
    expect(calendarEvents($fx)['b3@booking.com']->status)->toBe(ExternalEventStatus::PENDING_REMOVAL);

    // Dopo 10 minuti dalla prima assenza: rimosso
    $this->travel(6)->minutes();
    $third = syncCalendar($fx);
    expect($third->events_removed)->toBe(1)
        ->and(calendarEvents($fx)['b3@booking.com']->status)->toBe(ExternalEventStatus::REMOVED);
});

it('una risposta d\'errore tra le due assenze non conferma la rimozione', function () {
    $fx = calendarFixture();
    $fx['http']->responses[$fx['url']] = icsResponse(otaIcs('booking', threeEvents()));
    syncCalendar($fx);
    $fx['http']->responses[$fx['url']] = icsResponse(otaIcs('booking', array_slice(threeEvents(), 0, 2)));
    syncCalendar($fx);

    $this->travel(11)->minutes();
    $fx['http']->responses[$fx['url']] = new RawResponse(503, [], '');
    syncCalendar($fx);

    expect(calendarEvents($fx)['b3@booking.com']->status)->toBe(ExternalEventStatus::PENDING_REMOVAL);
});

it('con un solo evento futuro la sua scomparsa è una cancellazione normale', function () {
    $fx = calendarFixture();
    $fx['http']->responses[$fx['url']] = icsResponse(otaIcs('booking', [threeEvents()[0]]));
    syncCalendar($fx);

    $fx['http']->responses[$fx['url']] = icsResponse(otaIcs('booking', []));
    $run = syncCalendar($fx);

    expect($run->outcome)->toBe(SyncRunOutcome::SUCCESS)
        ->and(calendarEvents($fx)['b1@booking.com']->status)->toBe(ExternalEventStatus::PENDING_REMOVAL);
});

it('regola della salvaguardia', function () {
    expect(IcalImportService::suspicious(2, 0, 2))->toBeTrue()
        ->and(IcalImportService::suspicious(10, 4, 6))->toBeTrue()
        ->and(IcalImportService::suspicious(10, 5, 5))->toBeFalse()
        ->and(IcalImportService::suspicious(1, 0, 1))->toBeFalse()
        ->and(IcalImportService::suspicious(0, 0, 0))->toBeFalse();
});
