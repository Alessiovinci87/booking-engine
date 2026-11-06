<?php

/*
 * Controllo statico (10): nessuna chiamata HTTP verso URL esterni fuori da SafeHttpFetcher (06, 08;
 * CLAUDE.md regola 10). Unica eccezione: gli adapter Stripe in App\Domain\Payments\Stripe, che parlano
 * solo con l'API di Stripe tramite stripe/stripe-php.
 */

const HTTP_ALLOWED = [
    'App\Domain\Calendar\SafeHttpFetcher',
    'App\Domain\Payments\Stripe',
];

arch('client HTTP di Laravel e Guzzle solo in SafeHttpFetcher e negli adapter Stripe')
    ->expect([
        'Illuminate\Support\Facades\Http',
        'Illuminate\Http\Client',
        'GuzzleHttp',
        'Psr\Http\Client',
    ])
    ->toOnlyBeUsedIn(HTTP_ALLOWED);

arch('funzioni di rete di PHP solo in SafeHttpFetcher e negli adapter Stripe')
    ->expect([
        'file_get_contents',
        'fopen',
        'curl_init',
        'curl_exec',
        'curl_multi_exec',
        'fsockopen',
        'stream_socket_client',
        'get_headers',
    ])
    ->toOnlyBeUsedIn(HTTP_ALLOWED);
