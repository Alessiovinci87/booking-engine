<?php

/*
 * Password con Argon2id (08). I parametri predefiniti sono quelli di PHP (64 MiB, 4 iterazioni);
 * i test li riducono in phpunit.xml per velocità.
 */
return [

    'driver' => 'argon2id',

    'bcrypt' => [
        'rounds' => (int) env('BCRYPT_ROUNDS', 12),
        'verify' => true,
        'limit' => null,
    ],

    'argon' => [
        'memory' => (int) env('ARGON_MEMORY', 65536),
        'threads' => (int) env('ARGON_THREADS', 1),
        'time' => (int) env('ARGON_TIME', 4),
        'verify' => true,
    ],

    'rehash_on_login' => true,

];
