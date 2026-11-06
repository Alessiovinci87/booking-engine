<?php

use App\Domain\Reservations\Enums\ReservationStatus;
use App\Domain\Reservations\Models\ReservationChange;
use Illuminate\Support\Carbon;

/*
 * 3.5: API delle prenotazioni del pannello (07): elenco con filtri, dettaglio, approvazione e rifiuto,
 * modifica T18 con If-Match, link di recupero T15 e rilascio T16, blocchi manuali e calendario.
 * Ogni cambio di stato passa dalla macchina a stati; risorse di altri tenant: 404.
 */

beforeEach(function () {
    Carbon::setTestNow('2027-06-01 10:00:00');
    $this->fx = bookableTenant('INSTANT');
    signedInOwner($this, $this->fx['tenant']);
});
afterEach(fn () => Carbon::setTestNow());

it('elenca con filtri per stato, periodo e codice, con paginazione a cursore', function () {
    $confirmed = reservationIn($this->fx, ReservationStatus::CONFIRMED, '[2027-07-01,2027-07-04)');
    $waiting = startCheckout($this->fx, '[2027-07-10,2027-07-14)');
    $failed = reservationIn($this->fx, ReservationStatus::PAYMENT_FAILED, '[2027-08-20,2027-08-25)');

    panelGet($this, '/api/v1/admin/reservations')->assertOk()->assertJsonCount(3, 'data')
        ->assertJsonPath('data.0.id', $failed->id)
        ->assertJsonPath('data.0.guest.last_name', 'Lovelace')
        ->assertJsonMissingPath('data.0.guest.email');

    panelGet($this, '/api/v1/admin/reservations?status[]=CONFIRMED&status[]=AWAITING_PAYMENT')->assertJsonCount(2, 'data');
    panelGet($this, '/api/v1/admin/reservations?from=2027-07-03&to=2027-07-11')->assertJsonCount(2, 'data');
    panelGet($this, '/api/v1/admin/reservations?q='.strtolower($waiting->code))->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $waiting->id);

    $page = panelGet($this, '/api/v1/admin/reservations?limit=2')->assertJsonCount(2, 'data');
    panelGet($this, '/api/v1/admin/reservations?limit=2&cursor='.$page->json('next_cursor'))
        ->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $confirmed->id)->assertJsonPath('next_cursor', null);

    panelGet($this, '/api/v1/admin/reservations?status[]=BOH')->assertStatus(400);
});

it('il dettaglio di un altro tenant è 404', function () {
    $other = bookableTenant('INSTANT');
    $foreign = reservationIn($other, ReservationStatus::CONFIRMED);

    panelGet($this, "/api/v1/admin/reservations/{$foreign->id}")->assertNotFound();
    panelPost($this, "/api/v1/admin/reservations/{$foreign->id}/cancel", ['refund_cents' => 0])->assertNotFound();
    panelGet($this, '/api/v1/admin/reservations')->assertJsonCount(0, 'data');
});

it('approva e rifiuta le richieste REQUEST', function () {
    $fx = bookableTenant('REQUEST');
    signedInOwner($this, $fx['tenant']);
    $a = reservationIn($fx, ReservationStatus::AWAITING_APPROVAL, '[2027-07-01,2027-07-04)');
    $b = reservationIn($fx, ReservationStatus::AWAITING_APPROVAL, '[2027-07-10,2027-07-14)');

    panelGet($this, "/api/v1/admin/reservations/{$a->id}")->assertJsonPath('economic_status', 'AUTHORIZED');

    // La risposta precede la capture (job dopo il commit); il dettaglio successivo mostra l'esito.
    panelPost($this, "/api/v1/admin/reservations/{$a->id}/approve")->assertOk()->assertJsonPath('status', 'CAPTURING');
    panelGet($this, "/api/v1/admin/reservations/{$a->id}")->assertJsonPath('status', 'CONFIRMED')->assertJsonPath('economic_status', 'DEPOSIT_PAID');

    panelPost($this, "/api/v1/admin/reservations/{$b->id}/reject", ['reason' => 'Date non gestibili'])
        ->assertOk()->assertJsonPath('status', 'REJECTED')->assertJsonPath('cancel_reason', 'Date non gestibili');

    panelPost($this, "/api/v1/admin/reservations/{$b->id}/approve")->assertStatus(409)->assertJsonPath('error.code', 'ILLEGAL_TRANSITION');
    expect(occupancyViolations($fx['tenant']))->toBe([]);
});

it('modifica date e ospiti con If-Match, prezzo ricalcolato e storico ReservationChange', function () {
    $id = panelPost($this, '/api/v1/admin/reservations', [
        'unit_id' => $this->fx['unit'], 'check_in' => '2027-07-10', 'check_out' => '2027-07-14', 'adults' => 2, 'discount_cents' => 1000,
        'guest' => ['first_name' => 'Grace', 'last_name' => 'Hopper', 'email' => 'grace@example.test', 'phone' => '+39 333 1111111', 'country_code' => 'US'],
        'locale' => 'en',
    ])->assertCreated()->json('id');
    $url = "/api/v1/admin/reservations/{$id}";

    panelPatch($this, $url, ['check_in' => '2027-07-11', 'check_out' => '2027-07-16'])->assertStatus(412)->assertJsonPath('error.code', 'VERSION_MISMATCH');
    panelPatch($this, $url, ['check_in' => '2027-07-11', 'check_out' => '2027-07-16'], 99)->assertStatus(412);

    panelPatch($this, $url, ['check_in' => '2027-07-11', 'check_out' => '2027-07-16', 'adults' => 3, 'note' => 'Una notte in più'], 1)
        ->assertOk()
        ->assertHeader('ETag', '"2"')
        ->assertJsonPath('check_in', '2027-07-11')
        ->assertJsonPath('check_out', '2027-07-16')
        ->assertJsonPath('adults', 3)
        // 5 notti × 120 € + pulizia 60 € − sconto 10 € (lo sconto originale resta).
        ->assertJsonPath('contract_total.amount_cents', 65000)
        ->assertJsonPath('changes.0.previous.check_in', '2027-07-10')
        ->assertJsonPath('changes.0.new.check_out', '2027-07-16')
        ->assertJsonPath('changes.0.previous.contract_total.amount_cents', 53000)
        ->assertJsonPath('changes.0.note', 'Una notte in più');

    // Le date vecchie liberate sono subito vendibili; quelle di un'altra prenotazione no.
    reservationIn($this->fx, ReservationStatus::CONFIRMED, '[2027-07-16,2027-07-20)');
    panelPatch($this, $url, ['check_in' => '2027-07-12', 'check_out' => '2027-07-18'], 2)
        ->assertStatus(409)->assertJsonPath('error.code', 'DATES_UNAVAILABLE');

    expect(inTenant($this->fx['tenant'], fn () => ReservationChange::query()->where('reservation_id', $id)->count()))->toBe(1)
        ->and(occupancyViolations($this->fx['tenant']))->toBe([]);
});

it('link di recupero e rilascio delle date solo da CAPTURE_FAILED', function () {
    $fx = bookableTenant('REQUEST');
    signedInOwner($this, $fx['tenant']);
    $failed = reservationIn($fx, ReservationStatus::CAPTURE_FAILED, '[2027-07-01,2027-07-04)');
    $other = reservationIn($fx, ReservationStatus::CAPTURE_FAILED, '[2027-07-10,2027-07-14)');

    panelPost($this, "/api/v1/admin/reservations/{$failed->id}/recovery-link", ['deadline_hours' => 30])->assertStatus(400);

    $response = panelPost($this, "/api/v1/admin/reservations/{$failed->id}/recovery-link", ['deadline_hours' => 12])
        ->assertOk()
        ->assertJsonPath('status', 'AWAITING_PAYMENT')
        ->assertJsonPath('payments.1.purpose', 'RECOVERY');
    expect($response->json('recovery.checkout_url'))->toContain('/_simulated-checkout/cs_fake_');

    panelPost($this, "/api/v1/admin/reservations/{$failed->id}/release-dates")->assertStatus(409);
    panelPost($this, "/api/v1/admin/reservations/{$other->id}/release-dates")->assertOk()->assertJsonPath('status', 'PAYMENT_FAILED');

    $waiting = reservationIn($fx, ReservationStatus::AWAITING_APPROVAL, '[2027-07-20,2027-07-24)');
    panelPost($this, "/api/v1/admin/reservations/{$waiting->id}/recovery-link", ['deadline_hours' => 12])
        ->assertStatus(409)->assertJsonPath('error.code', 'ILLEGAL_TRANSITION');

    expect(occupancyViolations($fx['tenant']))->toBe([]);
});

it('crea e rimuove blocchi manuali; le date occupate sono rifiutate', function () {
    $unit = $this->fx['unit'];
    $block = panelPost($this, "/api/v1/admin/units/{$unit}/blocks", ['check_in' => '2027-07-20', 'check_out' => '2027-07-25', 'note' => 'Manutenzione'])
        ->assertCreated()
        ->assertJsonPath('kind', 'MANUAL_BLOCK')
        ->assertJsonPath('phase', 'FIRM')
        ->assertJsonPath('note', 'Manutenzione')
        ->json('id');

    panelPost($this, "/api/v1/admin/units/{$unit}/blocks", ['check_in' => '2027-07-24', 'check_out' => '2027-07-26'])
        ->assertStatus(409)->assertJsonPath('error.code', 'DATES_UNAVAILABLE');
    panelPost($this, "/api/v1/admin/units/{$unit}/blocks", ['check_in' => '2027-07-26', 'check_out' => '2027-07-26'])->assertStatus(400);

    $this->withHeader('Referer', TEST_PANEL_ORIGIN.'/app')->deleteJson("/api/v1/admin/units/{$unit}/blocks/{$block}")->assertNoContent();
    $this->withHeader('Referer', TEST_PANEL_ORIGIN.'/app')->deleteJson("/api/v1/admin/units/{$unit}/blocks/{$block}")->assertNotFound();

    panelPost($this, "/api/v1/admin/units/{$unit}/blocks", ['check_in' => '2027-07-24', 'check_out' => '2027-07-26'])->assertCreated();

    // Una prenotazione non si rimuove come blocco.
    $r = reservationIn($this->fx, ReservationStatus::CONFIRMED, '[2027-07-01,2027-07-04)');
    $occupancy = panelGet($this, "/api/v1/admin/calendar?unit_id={$unit}&from=2027-07-01&to=2027-08-01")->json('occupancies.0.id');
    $this->withHeader('Referer', TEST_PANEL_ORIGIN.'/app')->deleteJson("/api/v1/admin/units/{$unit}/blocks/{$occupancy}")->assertNotFound();
    expect(reloadReservation($this->fx, $r->id)->status)->toBe(ReservationStatus::CONFIRMED);
});

it('il calendario mostra occupazioni con origine, eventi esterni e conflitti', function () {
    // Calendari esterni solo su unità REQUEST (I12).
    $this->fx = bookableTenant('REQUEST');
    signedInOwner($this, $this->fx['tenant']);
    $unit = $this->fx['unit'];
    $r = reservationIn($this->fx, ReservationStatus::AWAITING_APPROVAL, '[2027-07-01,2027-07-04)');
    startCheckout($this->fx, '[2027-07-10,2027-07-14)');
    panelPost($this, "/api/v1/admin/units/{$unit}/blocks", ['check_in' => '2027-07-20', 'check_out' => '2027-07-22'])->assertCreated();
    createExternalEventRow($this->fx['tenant'], $unit, '[2027-08-01,2027-08-05)');
    createConflictRow($this->fx['tenant'], $unit, '[2027-08-10,2027-08-12)');

    $calendar = panelGet($this, "/api/v1/admin/calendar?unit_id={$unit}&from=2027-07-01&to=2027-09-01")->assertOk()
        ->assertJsonCount(3, 'occupancies')
        ->assertJsonPath('occupancies.0.kind', 'RESERVATION')
        ->assertJsonPath('occupancies.0.temp_reason', 'AWAITING_APPROVAL')
        ->assertJsonPath('occupancies.0.reservation.code', $r->code)
        ->assertJsonPath('occupancies.0.reservation.guest_name', 'Ada Lovelace')
        ->assertJsonPath('occupancies.1.phase', 'TEMPORARY')
        ->assertJsonPath('occupancies.1.reservation.status', 'AWAITING_PAYMENT')
        ->assertJsonPath('occupancies.2.kind', 'MANUAL_BLOCK')
        ->assertJsonPath('occupancies.2.reservation', null)
        ->assertJsonCount(1, 'external_events')
        ->assertJsonCount(1, 'conflicts');

    expect($calendar->json('external_events.0.check_in'))->toBe('2027-08-01');

    panelGet($this, "/api/v1/admin/calendar?unit_id={$unit}&from=2027-07-01&to=2029-07-01")->assertStatus(400);
    $other = bookableTenant('INSTANT');
    panelGet($this, "/api/v1/admin/calendar?unit_id={$other['unit']}&from=2027-07-01&to=2027-08-01")->assertNotFound();
});
