<?php

use App\Domain\Reservations\StateMachines\ReservationStateMachine;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/*
 * Finestra di approvazione per struttura (03 "Tempi", decisione dopo la verifica di M3, ADR-0012
 * punto 22): properties.approval_window_hours 1–48 h (predefinita 12), impostabile dal pannello, e
 * comunque mai oltre capture_before − 6 h. Stripe simulato.
 */

beforeEach(function () {
    Carbon::setTestNow('2027-06-01 10:00:00');
    $this->sim = stripeSimulator();
    $this->fx = bookableTenant('REQUEST');
});
afterEach(function () {
    resetStripeHttp();
    Carbon::setTestNow();
});

function setApprovalWindow(array $fx, int $hours): void
{
    inTenant($fx['tenant'], fn () => DB::table('properties')->where('id', $fx['property'])->update(['approval_window_hours' => $hours]));
}

it('una struttura nuova ha 12 ore', function () {
    expect(inTenant($this->fx['tenant'], fn () => DB::table('properties')->where('id', $this->fx['property'])->value('approval_window_hours')))->toBe(12);
});

it('usa la finestra della struttura', function () {
    setApprovalWindow($this->fx, 30);
    $reservation = stripeGuestPays($this, $this->sim, $this->fx, startCheckout($this->fx));

    expect($reservation->approval_deadline->toIso8601String())->toBe('2027-06-02T16:00:00+00:00');
});

it('la finestra della struttura resta limitata a capture_before − 6 h', function () {
    setApprovalWindow($this->fx, 48);
    $reservation = startCheckout($this->fx);
    $sessionId = (string) latestPayment($this->fx, $reservation->id)->checkout_session_id;
    $intent = $this->sim->pay($sessionId);
    $this->sim->intents[$intent['id']]['latest_charge']['payment_method_details']['card']['capture_before'] = now()->addHours(36)->getTimestamp();
    postStripeWebhook($this, $this->sim->event('checkout.session.completed', $this->sim->lastSessionView($sessionId), $this->fx['account']))->assertOk();

    // 48 h chieste, ma capture_before è a +36 h: scadenza a +30 h.
    expect(reloadReservation($this->fx, $reservation->id)->approval_deadline->toIso8601String())->toBe('2027-06-02T16:00:00+00:00');
});

it('calcola la scadenza dentro i limiti', function (int $window, ?int $captureInHours, string $expected) {
    $now = CarbonImmutable::parse('2027-06-01 10:00:00');
    $captureBefore = $captureInHours === null ? null : $now->addHours($captureInHours);

    expect(ReservationStateMachine::approvalDeadline($now, $window, $captureBefore)->toIso8601String())->toBe($expected);
})->with([
    'predefinita' => [12, null, '2027-06-01T22:00:00+00:00'],
    'minima' => [1, 168, '2027-06-01T11:00:00+00:00'],
    'massima' => [48, 168, '2027-06-03T10:00:00+00:00'],
    'oltre il massimo' => [72, null, '2027-06-03T10:00:00+00:00'],
    'capture_before vicina' => [24, 10, '2027-06-01T14:00:00+00:00'],
    'capture_before esattamente al limite' => [24, 30, '2027-06-02T10:00:00+00:00'],
]);

it('il pannello imposta la finestra tra 1 e 48 ore', function () {
    signedInOwner($this, $this->fx['tenant']);
    $url = "/api/v1/admin/properties/{$this->fx['property']}";
    $version = panelGet($this, $url)->assertOk()->assertJsonPath('approval_window_hours', 12)->json('version');

    panelPatch($this, $url, ['approval_window_hours' => 0], $version)->assertStatus(400)->assertJsonPath('error.code', 'VALIDATION_FAILED');
    panelPatch($this, $url, ['approval_window_hours' => 49], $version)->assertStatus(400);
    panelPatch($this, $url, ['approval_window_hours' => 24], $version)->assertOk()->assertJsonPath('approval_window_hours', 24);

    $reservation = stripeGuestPays($this, $this->sim, $this->fx, startCheckout($this->fx));
    expect($reservation->approval_deadline->toIso8601String())->toBe('2027-06-02T10:00:00+00:00');
});

it('il database rifiuta una finestra fuori intervallo', function () {
    expect(fn () => setApprovalWindow($this->fx, 49))->toThrow(QueryException::class);
});
