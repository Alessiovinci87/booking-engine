<?php

// Allerte operative interne (08, 6.5): nomi dei segnali per le email alla piattaforma (solo italiano).
return [
    'alert.queue_delayed' => 'coda in ritardo: messaggi in attesa da oltre 5 minuti',
    'stripe.webhook_failing' => 'webhook Stripe che falliscono ripetutamente',
    'payments.capturing_overdue' => 'prenotazione in CAPTURING da oltre un’ora',
    'occupancy_release.overdue' => 'occupazione scaduta non rilasciata da oltre 30 minuti',
    'invariant.i15_violation' => 'incoerenza tra stato e occupazione (I15): incidente P1',
    'payments.refund_failed_final' => 'rimborso fallito dopo tre tentativi',
    'payments.refund_reversed' => 'rimborso riuscito dichiarato fallito da Stripe',
    'security.webhook_tenant_mismatch' => 'webhook Stripe con tenant non corrispondente',
    'alert.calendars_unhealthy_daily' => 'riepilogo giornaliero dei calendari non sincronizzati',
];
