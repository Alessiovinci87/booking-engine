<?php

use App\Domain\Payments\Enums\RefundStatus;
use App\Domain\Payments\Jobs\ReconcilePaymentsJob;
use App\Domain\Payments\Models\Refund;
use App\Domain\Reservations\CancellationRefund;
use App\Domain\Tenancy\Models\OutboxMessage;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/*
 * AC19 (01, 03 sezione 3, 05): un rimborso rifiutato da Stripe diventa FAILED e si riprova da solo
 * dopo 1 h, 6 h e 24 h, ogni volta con una chiave nuova (nessun rimborso in corso); poi allerta al
 * gestore, che può riprovare o segnarlo come rimborsato fuori piattaforma. Stripe simulato.
 */

beforeEach(function () {
    Carbon::setTestNow('2027-06-01 10:00:00');
    $this->sim = stripeSimulator();
    $this->fx = bookableTenant('INSTANT');
    $this->reservation = stripeGuestPays($this, $this->sim, $this->fx, startCheckout($this->fx));
});
afterEach(function () {
    resetStripeHttp();
    Carbon::setTestNow();
});

function cancelWithRefund(array $fx, $reservation): Refund
{
    inTenant($fx['tenant'], fn () => machine()->cancel($reservation, CancellationRefund::for($reservation, null), null, null));

    return inTenant($fx['tenant'], fn () => Refund::query()->sole());
}

function refundOf(array $fx, string $id): Refund
{
    return inTenant($fx['tenant'], fn () => Refund::query()->findOrFail($id));
}

it('rifiuti ripetuti: tentativi a 1 h, 6 h, 24 h con chiavi nuove, poi allerta al gestore', function () {
    $this->sim->http->fail('post', '/v1/refunds', 400, 'invalid_request_error', 'charge_disputed');
    $refund = cancelWithRefund($this->fx, $this->reservation);

    expect($refund->status)->toBe(RefundStatus::FAILED)
        ->and($refund->attempts)->toBe(1)
        ->and($refund->next_attempt_at->toIso8601String())->toBe('2027-06-01T11:00:00+00:00');

    foreach (['2027-06-01 11:00:00' => '2027-06-01T17:00:00+00:00', '2027-06-01 17:00:00' => '2027-06-02T17:00:00+00:00', '2027-06-02 17:00:00' => null] as $at => $next) {
        Carbon::setTestNow($at);
        ReconcilePaymentsJob::dispatchSync($this->fx['tenant']);
        $current = refundOf($this->fx, $refund->id);
        expect($current->status)->toBe(RefundStatus::FAILED)
            ->and($current->next_attempt_at?->toIso8601String())->toBe($next);
    }

    $keys = array_column($this->sim->http->requestsTo('post', '/v1/refunds'), 'idempotency_key');
    expect($keys)->toBe(['refund:'.$refund->id, 'refund:'.$refund->id.':1', 'refund:'.$refund->id.':2', 'refund:'.$refund->id.':3'])
        ->and(inTenant($this->fx['tenant'], fn () => OutboxMessage::query()->where('type', 'reservation.refund_failed')->count()))->toBe(1)
        ->and(inTenant($this->fx['tenant'], fn () => OutboxMessage::query()->where('type', 'reservation.refund_completed')->count()))->toBe(0);
});

it('il tentativo automatico riuscito chiude il rimborso', function () {
    $this->sim->http->fail('post', '/v1/refunds', 400, 'invalid_request_error', 'processing_error', times: 1);
    $refund = cancelWithRefund($this->fx, $this->reservation);
    expect($refund->status)->toBe(RefundStatus::FAILED);

    Carbon::setTestNow('2027-06-01 11:00:00');
    ReconcilePaymentsJob::dispatchSync($this->fx['tenant']);

    expect(refundOf($this->fx, $refund->id)->status)->toBe(RefundStatus::SUCCEEDED)
        ->and(inTenant($this->fx['tenant'], fn () => OutboxMessage::query()->where('type', 'reservation.refund_completed')->count()))->toBe(1);
});

it('"Riprova" dal pannello: FAILED → PENDING e nuova chiamata', function () {
    $this->sim->http->fail('post', '/v1/refunds', 400, 'invalid_request_error', 'processing_error', times: 1);
    $refund = cancelWithRefund($this->fx, $this->reservation);
    $owner = panelUser($this->fx['tenant']);
    actingAsPanelUser($this, $owner['user']);

    panelGet($this, '/api/v1/admin/refunds?status[]=FAILED')->assertOk()
        ->assertJsonPath('data.0.id', $refund->id)
        ->assertJsonPath('data.0.reservation.code', $this->reservation->code);

    panelPost($this, "/api/v1/admin/refunds/{$refund->id}/retry")->assertOk();

    expect(refundOf($this->fx, $refund->id)->status)->toBe(RefundStatus::SUCCEEDED);
    panelPost($this, "/api/v1/admin/refunds/{$refund->id}/retry")->assertStatus(409);
});

it('"Segna come rimborsato fuori piattaforma": nota obbligatoria, metodo OFFLINE, email di completamento', function () {
    $this->sim->http->fail('post', '/v1/refunds', 400, 'invalid_request_error', 'processing_error');
    $refund = cancelWithRefund($this->fx, $this->reservation);
    $owner = panelUser($this->fx['tenant']);
    actingAsPanelUser($this, $owner['user']);

    panelPost($this, "/api/v1/admin/refunds/{$refund->id}/mark-offline", ['note' => ' '])->assertStatus(400)->assertJsonPath('error.code', 'VALIDATION_FAILED');
    panelPost($this, "/api/v1/admin/refunds/{$refund->id}/mark-offline", ['note' => 'Bonifico del 2 giugno'])
        ->assertOk()->assertJsonPath('data.status', 'SUCCEEDED')->assertJsonPath('data.method', 'OFFLINE');

    $audit = inTenant($this->fx['tenant'], fn () => DB::table('audit_log')->where('action', 'refund.succeeded')->value('changes'));
    expect(inTenant($this->fx['tenant'], fn () => OutboxMessage::query()->where('type', 'reservation.refund_completed')->count()))->toBe(1)
        ->and($audit)->not->toContain('Bonifico');
});

it('i rimborsi di un altro tenant non si vedono né si toccano', function () {
    $this->sim->http->fail('post', '/v1/refunds', 400, 'invalid_request_error', 'processing_error');
    $refund = cancelWithRefund($this->fx, $this->reservation);
    signedInOwner($this);

    panelGet($this, '/api/v1/admin/refunds')->assertOk()->assertJsonPath('data', []);
    panelPost($this, "/api/v1/admin/refunds/{$refund->id}/retry")->assertNotFound();
});
