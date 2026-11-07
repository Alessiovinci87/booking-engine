<?php

/*
 * Conservazione e cancellazione dei dati (09; 7.1 uscita del cliente, 7.2 job di conservazione).
 * Le durate NON sono qui: stanno in retention_policies (CLAUDE.md regola 17).
 */
return [

    // Come si ottengono i privilegi del ruolo app_retention (ADR-0018):
    // - "connection": connessione dedicata pgsql_retention (staging e produzione);
    // - "set_role": SET LOCAL ROLE app_retention sulla connessione predefinita, dentro la transazione
    //   (test e sviluppo locale, dove app_runtime ha il permesso di SET ROLE senza ereditare i privilegi).
    'db_mode' => env('RETENTION_DB_MODE', 'connection'),

    'connection' => 'pgsql_retention',

];
