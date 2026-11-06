import { execSync } from 'node:child_process';
import { existsSync, mkdirSync, rmSync } from 'node:fs';

import { SCREENSHOT_DIR } from './support/screenshots';

/**
 * Database demo appena rigenerato: migrate:fresh con app_migrator (l'unico ruolo che può farlo),
 * poi il seeder demo con app_runtime. La password del proprietario demo è casuale e il seeder la
 * stampa una sola volta: la si legge dall'output e passa ai test tramite l'ambiente.
 *
 * Rifiuta di girare se l'ambiente non è local: il comando cancella il database di sviluppo.
 */
export default function globalSetup(): void {
    const env = artisan(['env']);

    if (!/\blocal\b/.test(env)) {
        throw new Error(`I test E2E girano solo con APP_ENV=local (trovato: ${env.trim()})`);
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
    return execSync(['php', 'artisan', ...args, '--no-ansi'].join(' '), { encoding: 'utf8' });
}
