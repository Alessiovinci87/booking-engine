<?php

/*
 * Privacy (09). I testi delle informative e l'elenco dei sub-responsabili sono documenti legali da
 * redigere con il professionista: qui ci sono solo i link.
 */
return [

    // Informativa della piattaforma come responsabile (09 requisito 2): link fisso nel piè di pagina
    // della pagina ospite. Obbligatoria prima del pilota (checklist di 08, 6.7).
    'platform_notice_url' => env('PLATFORM_PRIVACY_NOTICE_URL'),

    // Elenco pubblico dei sub-responsabili (09 requisito 5).
    'subprocessors_url' => env('PLATFORM_SUBPROCESSORS_URL'),

];
