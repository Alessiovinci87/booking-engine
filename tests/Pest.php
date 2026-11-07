<?php

use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Configurazione di Pest
|--------------------------------------------------------------------------
|
| Unit/    Pest senza database
| Feature/ Pest con PostgreSQL reale e RLS (docs/10-TRACEABILITY.md, convenzioni dei test)
|
*/

pest()->extend(TestCase::class)->in('Feature');

require_once __DIR__.'/Support/tenancy.php';
require_once __DIR__.'/Support/source.php';
require_once __DIR__.'/Support/fixtures.php';
require_once __DIR__.'/Support/auth.php';
require_once __DIR__.'/Support/concurrency.php';
require_once __DIR__.'/Support/booking.php';
require_once __DIR__.'/Support/stripe.php';
