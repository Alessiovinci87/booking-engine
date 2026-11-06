import { defineConfig, devices } from '@playwright/test';

/**
 * Test E2E (ADR-0006: Playwright). Per ora solo in locale (headless), sul server di sviluppo e sul
 * database demo rigenerato da tests/E2E/global-setup.ts; in CI da M6 (11, attività 6.6).
 *
 * L'indirizzo deve restare http://127.0.0.1:8000: è tra i domini stateful di Sanctum (.env), senza
 * i quali il pannello non riceve il cookie di sessione.
 */
export default defineConfig({
    testDir: 'tests/E2E',
    globalSetup: './tests/E2E/global-setup.ts',
    fullyParallel: false,
    workers: 1,
    retries: 0,
    timeout: 120_000,
    expect: { timeout: 10_000 },
    reporter: [['list']],
    use: {
        baseURL: 'http://127.0.0.1:8000',
        locale: 'it-IT',
        timezoneId: 'Europe/Rome',
        trace: 'retain-on-failure',
        ...devices['Desktop Chrome'],
    },
    webServer: {
        command: 'php artisan serve --host=127.0.0.1 --port=8000',
        url: 'http://127.0.0.1:8000/up',
        reuseExistingServer: true,
        timeout: 60_000,
    },
});
