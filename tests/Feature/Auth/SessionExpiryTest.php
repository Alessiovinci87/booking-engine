<?php

/*
 * Durata della sessione del pannello (08): 2 ore di inattività, 12 ore assolute.
 */

afterEach(fn () => $this->travelBack());

it('mantiene la sessione con attività entro le 2 ore', function () {
    $p = panelUser();
    actingAsPanelUser($this, $p['user']);

    $this->travel(119)->minutes();
    $this->getJson('/api/v1/admin/me')->assertOk();

    $this->travel(119)->minutes();
    $this->getJson('/api/v1/admin/me')->assertOk();
});

it('scade dopo 2 ore di inattività', function () {
    $p = panelUser();
    actingAsPanelUser($this, $p['user']);

    $this->travel(121)->minutes();

    $this->getJson('/api/v1/admin/me')
        ->assertUnauthorized()
        ->assertJsonPath('error.code', 'UNAUTHENTICATED');

    $this->assertGuest('web');
});

it('scade dopo 12 ore anche con attività continua', function () {
    $p = panelUser();
    actingAsPanelUser($this, $p['user']);

    foreach (range(1, 6) as $i) {
        $this->travel(110)->minutes();
        $this->getJson('/api/v1/admin/me')->assertOk();
    }

    // 660 minuti finora; altri 61 superano le 12 ore.
    $this->travel(61)->minutes();
    $this->getJson('/api/v1/admin/me')->assertUnauthorized();
});

it('considera scaduta una sessione autenticata senza i dati del pannello', function () {
    $p = panelUser();

    $this->actingAs($p['user'], 'web');
    panelGet($this, '/api/v1/admin/me')->assertUnauthorized()->assertJsonPath('error.code', 'UNAUTHENTICATED');
});
