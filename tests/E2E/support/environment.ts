/**
 * Ambiente dei test E2E: database dedicato booking_engine_e2e e server PHP dedicato sulla porta
 * 8001, così npm run test:e2e non tocca il database di sviluppo né il server di `php artisan serve`.
 *
 * Le variabili d'ambiente reali prevalgono su quelle di .env (Laravel non le sovrascrive). Il server
 * è `php -S` e non `php artisan serve`, che passerebbe al server PHP solo un elenco chiuso di
 * variabili e quindi ignorerebbe queste.
 */
export const E2E_PORT = 8001;

export const E2E_BASE_URL = `http://127.0.0.1:${E2E_PORT}`;

export const E2E_ENV: Record<string, string> = {
    APP_ENV: 'local',
    APP_URL: E2E_BASE_URL,
    DB_DATABASE: process.env.DB_E2E_DATABASE ?? 'booking_engine_e2e',
    SANCTUM_STATEFUL_DOMAINS: `127.0.0.1:${E2E_PORT}`,
    SESSION_COOKIE: 'booking_engine_e2e_session',
    REDIS_PREFIX: 'booking-engine-e2e-database-',
    CACHE_PREFIX: 'booking-engine-e2e-cache-',
    // Nessun worker nei test: i job (es. esito del pagamento simulato) girano subito.
    QUEUE_CONNECTION: 'sync',
    // Email nella casella di anteprima /dev/mail (6.1), mai a un servizio esterno.
    MAIL_MAILER: 'log',
    MAIL_PREVIEW: 'true',
    // Spec di M4 con Stripe in modalità test: E2E_PAYMENT_GATEWAY=stripe (vedi m4-stripe-checkout.spec.ts).
    PAYMENT_GATEWAY: process.env.E2E_PAYMENT_GATEWAY ?? 'fake',
};
