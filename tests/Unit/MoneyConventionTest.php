<?php

/*
 * Verifica minima della suite Unit (senza database). I test di dominio arrivano dalle milestone
 * successive; questo controlla che PHP gestisca gli importi in centesimi come interi a 64 bit
 * (CLAUDE.md regola 6: denaro in centesimi interi, BIGINT nel database).
 */

it('rappresenta gli importi in centesimi con interi a 64 bit', function () {
    expect(PHP_INT_SIZE)->toBe(8)
        ->and(158_500 + 1)->toBeInt();
});
