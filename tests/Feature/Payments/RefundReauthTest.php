<?php

use App\Domain\Payments\Models\Refund;
use App\Domain\Reservations\Enums\ReservationStatus;
use App\Domain\Reservations\Models\Reservation;
use Illuminate\Support\Carbon;

/*
 * Decisione dopo la verifica di M4 (ADR-0012 punto 24, 08): ogni rimborso dal pannello richiede la
 * password confermata negli ultimi 15 minuti. Cancellazione con rimborso > 0, "Riprova" e "Segna
 * come rimborsato fuori piattaforma" → 403 REAUTH_REQUIRED senza conferma; la cancellazione senza
 * rimborso non la richiede.
 */

beforeEach(function () {
    Carbon::setTestNow('2027-06-01 10:00:00');
    $this->fx = bookableTenant('INSTANT');
    $this->owner = panelUser($this->fx['tenant']);
    actingAsPanelUser($this, $this->owner['user']);
    $this->reservation = reservationIn($this->fx, ReservationStatus::CONFIRMED);
    $this->url = "/api/v1/admin/reservations/{$this->reservation->id}/cancel";
});
afterEach(fn () => Carbon::setTestNow());

it('cancellazione con rimborso > 0: 403 REAUTH_REQUIRED senza conferma, nulla cambia', function () {
    panelPost($this, $this->url, ['refund_cents' => 5000])->assertForbidden()->assertJsonPath('error.code', 'REAUTH_REQUIRED');
    // Anche con il rimborso proposto dalla politica (refund_cents assente, proposto > 0).
    panelPost($this, $this->url)->assertForbidden()->assertJsonPath('error.code', 'REAUTH_REQUIRED');

    expect(inTenant($this->fx['tenant'], fn () => Reservation::query()->findOrFail($this->reservation->id))->status)->toBe(ReservationStatus::CONFIRMED)
        ->and(inTenant($this->fx['tenant'], fn () => Refund::query()->count()))->toBe(0)
        ->and(occupancyViolations($this->fx['tenant']))->toBe([]);

    $this->postJson('/api/v1/admin/auth/confirm-password', ['password' => TEST_PANEL_PASSWORD])->assertNoContent();
    panelPost($this, $this->url, ['refund_cents' => 5000])->assertOk()->assertJsonPath('status', 'CANCELLED');
});

it('cancellazione senza rimborso: nessuna ri-autenticazione', function () {
    panelPost($this, $this->url, ['refund_cents' => 0])->assertOk()->assertJsonPath('status', 'CANCELLED');
});

it('dopo 15 minuti la conferma va ripetuta', function () {
    $this->postJson('/api/v1/admin/auth/confirm-password', ['password' => TEST_PANEL_PASSWORD])->assertNoContent();
    Carbon::setTestNow(now()->addMinutes(16));
    actingAsPanelUser($this, $this->owner['user']);

    panelPost($this, $this->url, ['refund_cents' => 5000])->assertForbidden()->assertJsonPath('error.code', 'REAUTH_REQUIRED');
});

it('"Riprova" e "Segna come rimborsato" richiedono la conferma', function () {
    // Il controllo precede la ricerca del rimborso: basta un identificativo qualsiasi.
    $refund = uuid7();

    panelPost($this, "/api/v1/admin/refunds/{$refund}/retry")->assertForbidden()->assertJsonPath('error.code', 'REAUTH_REQUIRED');
    panelPost($this, "/api/v1/admin/refunds/{$refund}/mark-offline", ['note' => 'Bonifico'])->assertForbidden()->assertJsonPath('error.code', 'REAUTH_REQUIRED');
});
