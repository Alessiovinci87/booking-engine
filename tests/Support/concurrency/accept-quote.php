<?php

/*
 * Processo figlio: l'ospite accetta lo stesso preventivo (QuoteService::accept con T1 e sessione sul
 * gateway finto), come POST /public/quotes/{token}/accept da più schede insieme.
 * Argomenti: start, tenant, token del preventivo.
 */

use App\Domain\Quotes\QuoteLinks;
use App\Domain\Quotes\QuoteService;
use App\Domain\Reservations\GuestDetails;
use App\Domain\Tenancy\Enums\Locale;
use App\Domain\Tenancy\Exceptions\BusinessRuleViolation;

require __DIR__.'/bootstrap.php';

[, , $tenant, $token] = $argv;

waitForStart($argv);

try {
    $result = app(QuoteService::class)->accept(
        $tenant,
        QuoteLinks::hash($token),
        new GuestDetails('Ada', 'Lovelace', 'ada@example.test', '+39 333 0000000', 'IT', Locale::EN),
        ['terms' => null, 'privacy' => 'v1'],
    );

    report(['ok' => true, 'repeat' => $result['repeat'], 'code' => $result['reservation']->code, 'checkout_url' => $result['checkout_url']]);
} catch (BusinessRuleViolation $e) {
    report(['ok' => false, 'code' => $e->errorCode]);
} catch (Throwable $e) {
    report(['ok' => false, 'code' => 'EXCEPTION', 'message' => $e::class.': '.mb_substr($e->getMessage(), 0, 300)]);
}
