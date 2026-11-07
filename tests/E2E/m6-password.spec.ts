import { type Browser, expect, type Page, test } from '@playwright/test';

import { runOutbox } from './support/artisan';
import { snap } from './support/screenshots';
import { secondsLeftInStep, totp } from './support/totp';

/*
 * 6.11 — Gestione password del gestore nel browser (ADR-0016), senza accesso diretto al database:
 * - password dimenticata → link letto dalla casella /dev/mail → nuova password → il login chiede ancora
 *   la verifica in due passaggi;
 * - cambio password da /app/account: la sessione corrente resta aperta, l'altra sessione si chiude.
 * Alla fine la password del proprietario demo torna quella del global setup, per gli spec successivi.
 * Serve la MFA configurata dallo spec di M2 nello stesso processo: npx playwright test m2-panel m6-password
 */

const OWNER_EMAIL = 'proprietario.demo@example.test';
const RESET_PASSWORD = 'E2E-reimpostata-q7w3-k9m2-2027';
const CHANGED_PASSWORD = 'E2E-cambiata-z4x8-p2n6-2027';

let originalPassword = '';

test.describe.configure({ mode: 'serial' });

test.beforeAll(() => {
    originalPassword = process.env.E2E_DEMO_PASSWORD ?? '';
});

test.beforeEach(() => {
    test.skip(process.env.E2E_DEMO_MFA_SECRET === undefined, 'Serve la MFA configurata dallo spec di M2 nello stesso processo.');
});

async function enterPassword(page: Page, password: string): Promise<void> {
    await page.goto('/app/login');
    await page.getByLabel('Email').fill(OWNER_EMAIL);
    await page.getByLabel('Password', { exact: true }).fill(password);
    await page.getByRole('button', { name: 'Accedi' }).click();
}

/** Codice nuovo a ogni accesso: quello precedente non vale una seconda volta (anti-replay). */
async function enterMfaCode(page: Page): Promise<void> {
    await expect(page.getByLabel('Codice a 6 cifre')).toBeVisible();
    await page.waitForTimeout(secondsLeftInStep() * 1000 + 500);
    await page.getByLabel('Codice a 6 cifre').fill(totp(process.env.E2E_DEMO_MFA_SECRET ?? ''));
    await page.getByRole('button', { name: 'Verifica' }).click();
    await expect(page.getByRole('heading', { name: 'Strutture', level: 1 })).toBeVisible();
}

async function signIn(page: Page, password: string): Promise<void> {
    await enterPassword(page, password);
    await enterMfaCode(page);
}

/** Testo dell'ultima email del tipo indicato per il proprietario demo, dopo un giro dell'outbox. */
async function readMail(page: Page, category: string): Promise<string> {
    runOutbox();
    await page.goto('/dev/mail');
    const item = page.getByTestId('dev-mail-item').filter({ hasText: OWNER_EMAIL }).filter({ hasText: category });
    await expect(item.first()).toBeVisible();
    await item.first().click();

    return (await page.getByTestId('dev-mail-message').locator('pre').textContent()) ?? '';
}

async function clearMailbox(page: Page): Promise<void> {
    await page.goto('/dev/mail');
    await page.getByRole('button', { name: 'Svuota la casella' }).click();
    await expect(page.getByTestId('dev-mail-item')).toHaveCount(0);
}

async function changePassword(page: Page, current: string, next: string): Promise<void> {
    await page.goto('/app/account');
    await page.getByLabel('Password attuale').fill(current);
    await page.getByLabel('Nuova password', { exact: true }).fill(next);
    await page.getByLabel('Ripeti la nuova password').fill(next);
    await page.getByRole('button', { name: 'Cambia password' }).click();
    await expect(page.getByTestId('password-changed')).toBeVisible();
}

async function newPage(browser: Browser): Promise<Page> {
    return (await browser.newContext()).newPage();
}

test('6.11: password dimenticata con il link della email; il login chiede ancora la MFA', async ({ page }) => {
    await clearMailbox(page);

    await test.step('richiesta del link dalla pagina di accesso', async () => {
        await page.goto('/app/login');
        await page.getByRole('link', { name: 'Password dimenticata?' }).click();
        await page.getByLabel('Email').fill(OWNER_EMAIL);
        await page.getByRole('button', { name: 'Invia il link' }).click();
        await expect(page.getByTestId('forgot-sent')).toBeVisible();
        await snap(page, 'm6-password-dimenticata');
    });

    const path = await test.step('link letto dalla casella /dev/mail', async () => {
        const text = await readMail(page, 'account.password_reset');
        const match = /\/app\/reimposta-password#token=[A-Za-z0-9_-]{43}/.exec(text);
        expect(match, 'link di reimpostazione nella email').not.toBeNull();

        return match?.[0] ?? '';
    });

    await test.step('nuova password dal link, che poi non vale più', async () => {
        await page.goto(path);
        await page.getByLabel('Nuova password', { exact: true }).fill(RESET_PASSWORD);
        await page.getByLabel('Ripeti la nuova password').fill(RESET_PASSWORD);
        await page.getByRole('button', { name: 'Salva la nuova password' }).click();
        await expect(page.getByTestId('reset-done')).toBeVisible();
        // Il token non resta nella barra degli indirizzi.
        expect(new URL(page.url()).hash).toBe('');
        process.env.E2E_DEMO_PASSWORD = RESET_PASSWORD;
        await snap(page, 'm6-password-reimpostata');

        // Navigazione completa (non un semplice cambio di frammento sulla stessa pagina).
        await page.goto('/app/login');
        await page.goto(path);
        await page.getByLabel('Nuova password', { exact: true }).fill('E2E-di-nuovo-r5t1-2027');
        await page.getByLabel('Ripeti la nuova password').fill('E2E-di-nuovo-r5t1-2027');
        await page.getByRole('button', { name: 'Salva la nuova password' }).click();
        await expect(page.getByRole('alert')).toContainText('è già stato usato');
    });

    await test.step('la vecchia password non vale; con la nuova serve comunque il codice', async () => {
        await enterPassword(page, originalPassword);
        await expect(page.getByRole('alert')).toContainText('Email o password non corrette');

        await enterPassword(page, RESET_PASSWORD);
        await expect(page.getByRole('heading', { name: 'Verifica in due passaggi' })).toBeVisible();
        // Senza il codice il pannello non si apre.
        await page.goto('/app/prenotazioni');
        await expect(page.getByLabel('Codice a 6 cifre')).toBeVisible();
        await enterMfaCode(page);
    });

    await test.step('email di avviso "la tua password è stata cambiata"', async () => {
        const text = await readMail(page, 'account.password_changed');
        expect(text).toContain('Tutte le altre sessioni aperte sono state chiuse');
    });
});

test('6.11: cambio password da /app/account; la sessione corrente resta, l\'altra si chiude', async ({ browser }) => {
    const current = process.env.E2E_DEMO_PASSWORD ?? '';
    const other = await newPage(browser);
    const page = await newPage(browser);

    await test.step('due sessioni dello stesso gestore', async () => {
        await signIn(other, current);
        await signIn(page, current);
    });

    await test.step('cambio password dalla pagina Account', async () => {
        await page.getByRole('link', { name: OWNER_EMAIL }).click();
        await expect(page.getByRole('heading', { name: 'Account', level: 1 })).toBeVisible();
        await changePassword(page, current, CHANGED_PASSWORD);
        process.env.E2E_DEMO_PASSWORD = CHANGED_PASSWORD;
        await snap(page, 'm6-password-cambiata');

        // La sessione che ha cambiato la password resta aperta.
        await page.goto('/app/prenotazioni');
        await expect(page.getByRole('heading', { name: 'Prenotazioni', level: 1 })).toBeVisible();
    });

    await test.step('l\'altra sessione è chiusa', async () => {
        await other.goto('/app/prenotazioni');
        await expect(other.getByRole('heading', { name: 'Accedi al pannello' })).toBeVisible();
    });

    await test.step('ripristino della password del global setup', async () => {
        await changePassword(page, CHANGED_PASSWORD, originalPassword);
        process.env.E2E_DEMO_PASSWORD = originalPassword;
    });

    await other.context().close();
    await page.context().close();
});
