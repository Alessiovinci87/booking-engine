<?php

use App\Domain\Payments\Gateway\Fake\FakeFailure;
use App\Domain\Payments\Gateway\Fake\FakeOperation;
use App\Domain\Reservations\Enums\ReservationStatus;
use App\Domain\Tenancy\Models\AuditLog;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/*
 * I7 (02): una cancellazione conserva lo storico degli eventi e delle operazioni economiche. T17 con
 * rimborso sul gateway finto: la risposta dice CANCELLED e refund_status PENDING, mai "rimborsato";
 * il rimborso si completa dopo (job) oppure fallisce con date comunque libere (03).
 */

beforeEach(function () {
    Carbon::setTestNow('2027-06-01 10:00:00');
    $this->fx = bookableTenant('INSTANT');
    signedInOwner($this, $this->fx['tenant'], passwordConfirmed: true); // rimborsi > 0: ri-autenticazione (ADR-0012 punto 24)
    $this->reservation = reservationIn($this->fx, ReservationStatus::CONFIRMED);
});
afterEach(fn () => Carbon::setTestNow());

it('cancella con il rimborso proposto dalla politica e conserva tutto lo storico', function () {
    $id = $this->reservation->id;
    $count = fn (string $table, string $column, array $ids) => inTenant($this->fx['tenant'], fn () => DB::table($table)->whereIn($column, $ids)->count());
    $paymentIds = inTenant($this->fx['tenant'], fn () => DB::table('payments')->where('reservation_id', $id)->pluck('id')->all());
    $before = [
        'events' => $count('payment_events', 'payment_id', $paymentIds),
        'lines' => $count('reservation_price_lines', 'reservation_id', [$id]),
        'audit' => inTenant($this->fx['tenant'], fn () => AuditLog::query()->where('subject_id', $id)->count()),
    ];

    // FLEXIBLE_7D a più di 7 giorni dall'arrivo: rimborso proposto = tutto l'incassato online (16200).
    panelPost($this, "/api/v1/admin/reservations/{$id}/cancel", ['reason' => 'Richiesta dell\'ospite'])
        ->assertOk()
        ->assertJsonPath('status', 'CANCELLED')
        ->assertJsonPath('refund_status', 'PENDING')
        ->assertJsonPath('refunds.0.amount.amount_cents', 16200)
        ->assertJsonPath('cancel_reason', 'Richiesta dell\'ospite');

    // Dopo il job del rimborso: completato, storico intatto e più ricco.
    $detail = panelGet($this, "/api/v1/admin/reservations/{$id}")->assertOk()
        ->assertJsonPath('refund_status', 'SUCCEEDED')
        ->assertJsonPath('economic_status', 'REFUNDED');

    expect($count('payment_events', 'payment_id', $paymentIds))->toBeGreaterThan($before['events'])
        ->and($count('reservation_price_lines', 'reservation_id', [$id]))->toBe($before['lines'])
        ->and(inTenant($this->fx['tenant'], fn () => AuditLog::query()->where('subject_id', $id)->count()))->toBeGreaterThan($before['audit'])
        ->and(collect($detail->json('history'))->pluck('action'))->toContain('reservation.start_checkout', 'reservation.payment_succeeded', 'reservation.cancel')
        ->and(collect($detail->json('payments.0.events'))->pluck('type'))->toContain('succeeded')
        ->and(occupancyViolations($this->fx['tenant']))->toBe([]);

    // Il motivo (testo libero) non finisce nell'audit (09).
    $audit = inTenant($this->fx['tenant'], fn () => AuditLog::query()->where('subject_id', $id)->get()->toJson());
    expect($audit)->not->toContain('Richiesta dell');
});

it('accetta un rimborso inferiore o nullo e rifiuta un rimborso oltre l\'incassato online', function () {
    panelPost($this, "/api/v1/admin/reservations/{$this->reservation->id}/cancel", ['refund_cents' => 16201])
        ->assertStatus(400)->assertJsonStructure(['error' => ['details' => ['fields' => ['refund_cents']]]]);

    panelPost($this, "/api/v1/admin/reservations/{$this->reservation->id}/cancel", ['refund_cents' => 0])
        ->assertOk()
        ->assertJsonPath('status', 'CANCELLED')
        ->assertJsonPath('refund_status', 'NONE')
        ->assertJsonCount(0, 'refunds');
});

it('un rimborso rifiutato dal servizio lascia CANCELLED, date libere e refund_status FAILED', function () {
    fakeGateway()->failNext(FakeOperation::REFUND, FakeFailure::DECLINED);

    panelPost($this, "/api/v1/admin/reservations/{$this->reservation->id}/cancel", ['refund_cents' => 5000])
        ->assertOk()->assertJsonPath('status', 'CANCELLED');

    panelGet($this, "/api/v1/admin/reservations/{$this->reservation->id}")
        ->assertJsonPath('status', 'CANCELLED')
        ->assertJsonPath('refunds.0.attempts', 1)
        ->assertJsonPath('refund_status', fn (string $s) => in_array($s, ['PENDING', 'FAILED'], true));

    expect(occupancyViolations($this->fx['tenant']))->toBe([])
        ->and(inTenant($this->fx['tenant'], fn () => DB::table('outbox_messages')->where('type', 'reservation.refund_completed')->count()))->toBe(0);
});

it('cancellare due volte è una transizione vietata', function () {
    panelPost($this, "/api/v1/admin/reservations/{$this->reservation->id}/cancel", ['refund_cents' => 0])->assertOk();
    panelPost($this, "/api/v1/admin/reservations/{$this->reservation->id}/cancel", ['refund_cents' => 0])
        ->assertStatus(409)->assertJsonPath('error.code', 'ILLEGAL_TRANSITION');
});
