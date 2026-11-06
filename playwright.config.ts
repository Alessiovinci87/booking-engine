import { defineConfig, devices } from '@playwright/test';

import { E2E_BASE_URL, E2E_ENV, E2E_PORT } from './tests/E2E/support/environment';

/**
 * Test E2E (ADR-0006: Playwright). Per ora solo in locale (headless); in CI da M6 (11, attività 6.6).
 * Database e server dedicati (tests/E2E/support/environment.ts): il database di sviluppo resta intatto.
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
        baseURL: E2E_BASE_URL,
        locale: 'it-IT',
        timezoneId: 'Europe/Rome',
        trace: 'retain-on-failure',
        ...devices['Desktop Chrome'],
    },
    webServer: {
        // server.php di Laravel usa la cartella corrente come public/.
        command: `php -S 127.0.0.1:${E2E_PORT} ../vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php`,
        cwd: 'public',
        url: `${E2E_BASE_URL}/up`,
        env: E2E_ENV,
        // Mai riusare un server già avviato: potrebbe puntare al database di sviluppo.
        reuseExistingServer: false,
        timeout: 60_000,
    },
});
