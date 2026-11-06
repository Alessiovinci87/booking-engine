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
