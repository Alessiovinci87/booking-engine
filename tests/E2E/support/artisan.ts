import { execSync } from 'node:child_process';

import { E2E_ENV } from './environment';

/**
 * Comandi artisan dei test E2E, con l'ambiente E2E (database booking_engine_e2e). Solo argomenti
 * costanti scritti negli spec: nessun input esterno nella riga di comando.
 */
export function artisan(args: string[]): string {
    return execSync(['php', 'artisan', ...args, '--no-ansi'].join(' '), { encoding: 'utf8', env: { ...process.env, ...E2E_ENV } });
}

/**
 * Un giro del dispatcher dell'outbox (in staging lo fa lo scheduler ogni minuto): con QUEUE_CONNECTION=sync
 * le email partono subito e, con MAIL_MAILER=log, finiscono nella casella /dev/mail.
 */
export function runOutbox(): void {
    artisan(['schedule:test', '--name=outbox:dispatch']);
}
