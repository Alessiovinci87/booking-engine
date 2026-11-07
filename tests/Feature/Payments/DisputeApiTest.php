<?php

use Illuminate\Support\Carbon;

/*
 * 4.10: GET /admin/disputes elenca le contestazioni aperte (dispute_created senza dispute_closed) con
 * prenotazione e importo, per l'avviso nel pannello; chiusa la contestazione, l'avviso sparisce. Le
 * contestazioni di un altro tenant non si vedono (RLS). Stripe simulato.
 */

beforeEach(function () {
    Carbon::setTestNow('2027-06-01 10:00:00');
    $this->sim = stripeSimulator();
    $this->fx = bookableTenant('INSTANT');
    $this->reservation = stripeGuestPays($this, $this->sim, $this->fx, startCheckout($this->fx));
    $intent = latestPayment($this->fx, $this->reservation->id)->payment_intent_id;
    $this->dispute = ['id' => 'dp_test_panel', 'object' => 'dispute', 'payment_intent' => $intent, 'charge' => 'ch_x', 'amount' => 14400, 'status' => 'needs_response', 'reason' => 'fraudulent'];
    $this->sim->http->on('get', '/v1/disputes/dp_test_panel', $this->dispute);
    postStripeWebhook($this, $this->sim->event('charge.dispute.created', $this->dispute, $this->fx['account']))->assertOk();
});
afterEach(function () {
    resetStripeHttp();
    Carbon::setTestNow();
});

it('contestazione aperta nell\'elenco, chiusa non più', function () {
    $owner = panelUser($this->fx['tenant']);
    actingAsPanelUser($this, $owner['user']);

    panelGet($this, '/api/v1/admin/disputes')->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.dispute_id', 'dp_test_panel')
        ->assertJsonPath('data.0.amount_cents', 14400)
        ->assertJsonPath('data.0.reservation.code', $this->reservation->code);

    $closed = [...$this->dispute, 'status' => 'won'];
    $this->sim->http->on('get', '/v1/disputes/dp_test_panel', $closed);
    postStripeWebhook($this, $this->sim->event('charge.dispute.closed', $closed, $this->fx['account']))->assertOk();

    panelGet($this, '/api/v1/admin/disputes')->assertOk()->assertJsonPath('data', []);
});

it('le contestazioni di un altro tenant non si vedono', function () {
    signedInOwner($this);

    panelGet($this, '/api/v1/admin/disputes')->assertOk()->assertJsonPath('data', []);
});
