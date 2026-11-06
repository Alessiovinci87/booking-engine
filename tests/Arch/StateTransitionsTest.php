<?php

/*
 * Controllo statico (10): nessun update(['status' => ...]) diretto sui modelli con macchina a stati
 * (03; CLAUDE.md regola 5). Gli stati cambiano solo tramite i metodi delle macchine a stati in
 * app/Domain/<Area>/StateMachines.
 *
 * Segnaposto finché non esistono i modelli (M1-M3): fallisce se trova la chiave 'status' in un array
 * (update, forceFill, fill, create, ...) in qualsiasi file di app/ fuori dalle StateMachines.
 */

it("nessuna chiave 'status' => fuori da app/Domain/*/StateMachines", function () {
    $offenders = sourceFilesContaining(
        ["'status' =>", '"status" =>', "'status'=>", '"status"=>'],
        exceptPattern: '#^app/Domain/[^/]+/StateMachines/#',
    );

    expect($offenders)->toBeEmpty(
        "Scrittura diretta dello stato fuori dalle macchine a stati:\n- ".implode("\n- ", $offenders)
    );
});
