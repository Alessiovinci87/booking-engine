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
    // Comando stripe:demo-account (4.1).
    'demo_account' => [
        'gateway_required' => 'Imposta PAYMENT_GATEWAY=stripe e le chiavi di test di Stripe nel file .env.',
        'live_key' => 'Il comando funziona solo con una chiave Stripe di test (sk_test_…).',
        'tenant_missing' => 'Tenant ":slug" inesistente: esegui prima php artisan db:seed --class=DemoSeeder.',
        'created' => 'Account Stripe connesso: :account',
        'link' => 'Completa l\'onboarding con i dati di prova di Stripe da questo link (scade dopo pochi minuti; per un link nuovo rilancia il comando):',
        'attached' => 'Account Stripe connesso collegato: :account',
        'not_enabled' => 'L\'account non ha ancora i pagamenti attivi: completa l\'onboarding (php artisan stripe:demo-account senza --account).',
    ],
];
