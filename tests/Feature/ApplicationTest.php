<?php

it('usa UTC per i timestamp e l\'italiano come lingua predefinita', function () {
    expect(config('app.timezone'))->toBe('UTC')
        ->and(date_default_timezone_get())->toBe('UTC')
        ->and(config('app.locale'))->toBe('it');
});

it('reindirizza la radice al pannello', function () {
    $this->get('/')->assertRedirect('/app');
});

it('risponde al controllo di salute', function () {
    $this->get('/up')->assertOk();
});
