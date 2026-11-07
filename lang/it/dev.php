<?php

// Pagine di sviluppo (solo APP_ENV=local, solo italiano).
return [
    'mail' => [
        'title' => 'Casella di anteprima delle email',
        'explanation' => 'Email inviate in locale con MAIL_MAILER=log. Solo sviluppo: non esiste in staging e produzione.',
        'clear' => 'Svuota la casella',
        'empty' => 'Nessuna email.',
        'from' => 'Da',
        'to' => 'A',
        'reply_to' => 'Rispondi a',
        'subject' => 'Oggetto',
        'category' => 'Tipo',
    ],
];
