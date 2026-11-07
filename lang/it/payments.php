<?php

// Pagina "Pagamento simulato" (3.3): solo sviluppo e test, con PAYMENT_GATEWAY=fake.
return [
    'simulated' => [
        'badge' => 'Ambiente di prova',
        'title' => 'Pagamento simulato',
        'explanation' => 'Nessun pagamento reale: scegli l\'esito da simulare. La conferma arriva come dal servizio di pagamento, non da questa pagina.',
        'pay' => 'Pagamento riuscito',
        'decline' => 'Pagamento rifiutato',
        'abandon' => 'Abbandona',
        'closed' => 'Questa sessione di pagamento non è più aperta.',
        'back' => 'Torna alla prenotazione',
    ],
];
