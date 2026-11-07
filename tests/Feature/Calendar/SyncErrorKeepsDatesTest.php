<?php

use App\Domain\Calendar\Enums\ExternalEventStatus;
use App\Domain\Calendar\Enums\SyncRunOutcome;
use App\Domain\Calendar\Http\FetchError;
use App\Domain\Calendar\Http\FetchFailed;
use App\Domain\Calendar\Http\RawResponse;
use Carbon\CarbonImmutable;

/*
 * 06 "Elaborazione" punto 1 e "Nel dubbio, si blocca" (I6): errore, timeout, URL bloccato, HTTP
 * d'errore, ICS non valido → SyncRun ERROR, nessuna modifica agli eventi; backoff fino a 60 minuti e
 * rispetto di Retry-After.
 */

function seededCalendar(): array
{
    $fx = calendarFixture();
    $fx['http']->responses[$fx['url']] = icsResponse(otaIcs('booking', [
        ['uid' => 'b1@booking.com', 'in' => futureDay(3), 'out' => futureDay(5)],
        ['uid' => 'b2@booking.com', 'in' => futureDay(8), 'out' => futureDay(9)],
    ]));
    syncCalendar($fx);

    return $fx;
}

it('un errore non tocca gli eventi e registra il codice', function (RawResponse|FetchFailed $failure, string $code) {
    $fx = seededCalendar();
    $fx['http']->responses[$fx['url']] = $failure;

    $run = syncCalendar($fx);
    $events = calendarEvents($fx);

    expect($run->outcome)->toBe(SyncRunOutcome::ERROR)
        ->and($run->error_code)->toBe($code)
        ->and($events)->toHaveCount(2)
        ->and($events['b1@booking.com']->status)->toBe(ExternalEventStatus::ACTIVE)
        ->and($events['b2@booking.com']->status)->toBe(ExternalEventStatus::ACTIVE)
        ->and($events['b1@booking.com']->missing_since)->toBeNull();
})->with([
    'timeout' => [new FetchFailed(FetchError::TIMEOUT), 'TIMEOUT'],
    'troppo grande' => [new FetchFailed(FetchError::TOO_LARGE), 'TOO_LARGE'],
    'TLS' => [new FetchFailed(FetchError::TLS_ERROR), 'TLS_ERROR'],
    'HTTP 500' => [new RawResponse(500, [], 'errore'), 'HTTP_500'],
    'HTTP 404' => [new RawResponse(404, [], ''), 'HTTP_404'],
    'pagina HTML' => [new RawResponse(200, [], '<html>login</html>'), 'INVALID_ICS'],
    'ICS troncato' => [new RawResponse(200, [], "BEGIN:VCALENDAR\r\nBEGIN:VEVENT\r\nDTSTART:2027"), 'INVALID_ICS'],
    'redirect verso IP privato' => [new RawResponse(302, ['location' => 'https://internal.example.com/x'], ''), 'BLOCKED_ADDRESS'],
]);

it('backoff crescente fino a 60 minuti; il successo azzera gli errori', function () {
    $fx = seededCalendar();
    $fx['http']->responses[$fx['url']] = new FetchFailed(FetchError::CONNECTION_FAILED);

    $waits = [];
    foreach (range(1, 5) as $_) {
        syncCalendar($fx);
        $calendar = freshCalendar($fx);
        $waits[] = (int) round(CarbonImmutable::now()->diffInSeconds($calendar->next_poll_at) / 60);
    }

    expect(freshCalendar($fx)->consecutive_failures)->toBe(5);

    foreach ([10, 20, 40, 60, 60] as $i => $minutes) {
        expect($waits[$i])->toBeGreaterThanOrEqual($minutes - 2)->toBeLessThanOrEqual($minutes + 2);
    }

    $fx['http']->responses[$fx['url']] = new RawResponse(304, [], '');
    syncCalendar($fx);
    expect(freshCalendar($fx)->consecutive_failures)->toBe(0);
});

it('Retry-After più lungo del backoff viene rispettato', function () {
    $fx = seededCalendar();
    $fx['http']->responses[$fx['url']] = new RawResponse(429, ['retry-after' => '7200'], '');

    $run = syncCalendar($fx);

    expect($run->error_code)->toBe('HTTP_429')
        ->and(CarbonImmutable::now()->diffInSeconds(freshCalendar($fx)->next_poll_at))->toBeGreaterThanOrEqual(7195);
});
