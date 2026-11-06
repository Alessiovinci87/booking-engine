<?php

/*
 * Foto delle unità (11, attività 2.2). Disco privato: in locale "local" (storage/app/private), in
 * staging e produzione S3 compatibile (ADR-0006). Lettura solo con URL firmati temporanei.
 */
return [
    'disk' => env('PHOTOS_DISK', 'local'),
    'url_ttl_minutes' => 60,
    'max_per_unit' => 20,
    'max_upload_kb' => 10240,
    // Limite di dimensioni prima della decodifica: evita immagini enormi in memoria.
    'max_source_pixels_side' => 12000,
    'sizes' => [
        'large' => 1600,
        'thumbnail' => 400,
    ],
    'webp_quality' => 82,
];
