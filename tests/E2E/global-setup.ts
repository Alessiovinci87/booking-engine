import { execSync } from 'node:child_process';
import { existsSync, mkdirSync, rmSync } from 'node:fs';

import { E2E_ENV } from './support/environment';
import { SCREENSHOT_DIR } from './support/screenshots';

/**
 * Database E2E dedicato (booking_engine_e2e) appena rigenerato: migrate:fresh con app_migrator
 * (l'unico ruolo che può farlo), poi il seeder demo con app_runtime. La password del proprietario
 * demo è casuale e il seeder la stampa una sola volta: la si legge dall'output e passa ai test
 * tramite l'ambiente. Il database di sviluppo non viene toccato.
 */
export default function globalSetup(): void {
    const env = artisan(['env']);

    if (!/\blocal\b/.test(env)) {
        throw new Error(`I test E2E girano solo con APP_ENV=local (trovato: ${env.trim()})`);
    }

    // Prima di cancellare qualcosa: la connessione deve puntare al database E2E, mai a quello di sviluppo.
    const database = artisan(['config:show', 'database.connections.pgsql.database']);

    if (!database.includes(E2E_ENV.DB_DATABASE ?? '') || !(E2E_ENV.DB_DATABASE ?? '').endsWith('_e2e')) {
        throw new Error(`La connessione non punta al database E2E (${E2E_ENV.DB_DATABASE}): nessuna modifica.`);
    }

    if (existsSync('public/hot')) {
        throw new Error('Ferma "npm run dev": i test E2E usano gli asset di "npm run build" (public/hot presente).');
    }

    artisan(['migrate:fresh', '--database=pgsql_migrator', '--force']);
    const output = artisan(['db:seed', '--class=DemoSeeder', '--force']);
    const password = /:\s*(\S+)\s*$/m.exec(output.split('\n').find((line) => line.includes('mostrata una sola volta')) ?? '')?.[1];

    if (password === undefined) {
        throw new Error('Password del proprietario demo non trovata nell\'output del seeder.');
    }

    process.env.E2E_DEMO_PASSWORD = password;

    rmSync(SCREENSHOT_DIR, { recursive: true, force: true });
    mkdirSync(SCREENSHOT_DIR, { recursive: true });
}

function artisan(args: string[]): string {
    // Argomenti costanti di questo file: nessun input esterno nella riga di comando.
    return execSync(['php', 'artisan', ...args, '--no-ansi'].join(' '), { encoding: 'utf8', env: { ...process.env, ...E2E_ENV } });
}
