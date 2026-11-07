<?php

/*
 * Avvio di Laravel nei processi figli dei test di concorrenza. Primo argomento: istante di partenza
 * comune (microtime), per far partire le richieste insieme dopo il bootstrap.
 */

use Illuminate\Contracts\Console\Kernel;

require __DIR__.'/../../../vendor/autoload.php';

$app = require __DIR__.'/../../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

/** @param  list<string>  $argv */
function waitForStart(array $argv): void
{
    $startAt = (float) ($argv[1] ?? 0);

    while (microtime(true) < $startAt) {
        usleep(1000);
    }
}

/** @param  array<string, mixed>  $result */
function report(array $result): never
{
    echo json_encode($result), "\n";

    exit(0);
}

return $app;
