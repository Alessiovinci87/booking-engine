<?php

/*
 * Calendari iCal (06).
 */
return [

    // Letture dei feed esportati consentite per token (06 "Formato").
    'feed_rate_limit_per_hour' => (int) env('ICAL_FEED_RATE_LIMIT_PER_HOUR', 60),

];
