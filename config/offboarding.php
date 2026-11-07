<?php

/*
 * Uscita del cliente (7.1; 06 "Ciclo di vita dei feed", 09 "Uscita del cliente", ADR-0018).
 * Le soglie sono quelle della specifica, non durate di conservazione (che stanno in retention_policies).
 */
return [

    // Disco privato degli export completi (mai pubblico): in produzione lo storage europeo (09).
    'disk' => env('OFFBOARDING_EXPORT_DISK', 'local'),

    // 06 punto 4: ritiro automatico dei feed dopo 30 giorni dall'avvio, se l'OTA non legge da 7 giorni.
    'auto_retire_after_days' => 30,
    'unread_days' => 7,

    // 09 punto 6: cancellazione dei dati almeno 30 giorni dopo l'export (salvo istruzione scritta).
    'purge_after_export_days' => 30,

];
