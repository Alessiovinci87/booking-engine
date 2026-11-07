<?php

/*
 * Inventario (02). L'UID iCal di ogni occupazione è stabile per tutta la sua vita:
 * occ-{id}@{dominio}. Il dominio predefinito è quello di APP_URL.
 */
return [
    'ics_uid_domain' => env('ICS_UID_DOMAIN'),

    // Disponibilità pubblica (07): intervallo massimo di una richiesta.
    'availability_max_months' => 18,
];
