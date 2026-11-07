import { expect, type Page, test } from '@playwright/test';

import { snap } from './support/screenshots';
import { secondsLeftInStep, totp } from './support/totp';

/*
 * Verifica di M5 (preventivi) nel browser, sul database demo appena rigenerato e con il gateway finto:
 * 1. il gestore crea dal pannello un preventivo con sconto e copia il link;
 * 2. l'ospite apre /q/{token} dal telefono, trova i dati precompilati e il prezzo congelato, accetta e
 *    paga sulla pagina di pagamento simulato; la conferma arriva dal servizio di pagamento (I6);
 * 3. il preventivo risulta accettato nel pannello e il link non è più pagabile.
 * Serve la MFA configurata dallo spec di M2 nello stesso processo: npx playwright test m2-panel m5-quote
 */

const OWNER_EMAIL = 'proprietario.demo@example.test';

function inDays(days: number): string {
    const date = new Date();
    date.setDate(date.getDate() + days);

    return `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-${String(date.getDate()).padStart(2, '0')}`;
}

async function signIn(page: Page): Promise<void> {
    await page.goto('/app');
    await page.getByLabel('Email').fill(OWNER_EMAIL);
    await page.getByLabel('Password').fill(process.env.E2E_DEMO_PASSWORD ?? '');
    await page.getByRole('button', { name: 'Accedi' }).click();
    // Codice nuovo: quello degli spec precedenti potrebbe essere già stato usato (anti-replay).
    await page.waitForTimeout(secondsLeftInStep() * 1000 + 500);
    await page.getByLabel('Codice a 6 cifre').fill(totp(process.env.E2E_DEMO_MFA_SECRET ?? ''));
    await page.getByRole('button', { name: 'Verifica' }).click();
    await expect(page.getByRole('heading', { name: 'Strutture', level: 1 })).toBeVisible();
}

test.describe.configure({ mode: 'serial' });

test.describe('M5 preventivi', () => {
    test('il gestore invia un preventivo, l\'ospite lo accetta e paga', async ({ browser }) => {
        test.skip(process.env.E2E_DEMO_MFA_SECRET === undefined, 'Serve la MFA configurata dallo spec di M2 nello stesso processo.');

        const panel = await browser.newContext({ locale: 'it-IT', timezoneId: 'Europe/Rome' });
        await panel.grantPermissions(['clipboard-read', 'clipboard-write']);
        const manager = await panel.newPage();
        let link = '';

        await test.step('pannello: nuovo preventivo con sconto', async () => {
            await signIn(manager);
            await manager.getByRole('link', { name: 'Preventivi' }).click();
            await expect(manager.getByRole('heading', { name: 'Preventivi', level: 1 })).toBeVisible();

            const form = manager.getByTestId('quote-form');
            await form.locator('select[name="unit_id"]').selectOption({ label: 'Bilocale Vista Mare' });
            await form.getByLabel('Check-in').fill(inDays(20));
            await form.getByLabel('Check-out').fill(inDays(23));
            await form.getByLabel('Sconto', { exact: false }).first().fill('20,00');
            await form.getByLabel('Nome dell’ospite').fill('Marta Esempio');
            await form.getByLabel('Email').fill('marta.esempio@example.test');
            await form.getByRole('button', { name: 'Crea preventivo' }).click();

            await expect(manager.getByTestId('quote-row').first()).toHaveAttribute('data-status', 'DRAFT');
            await snap(manager, 'pannello-preventivo-creato');
        });

        await test.step('copia link: il preventivo passa a Inviato', async () => {
            const row = manager.getByTestId('quote-row').first();
            await row.getByRole('button', { name: 'Copia link' }).click();
            await expect(row.getByTestId('quote-link')).toHaveText(/\/q\/[A-Za-z0-9_-]{43}$/);
            await expect(row).toHaveAttribute('data-status', 'SENT');
            await expect(row.getByRole('button', { name: 'Apri WhatsApp' })).toBeVisible();
            link = (await row.getByTestId('quote-link').textContent())?.trim() ?? '';
            await snap(manager, 'pannello-preventivo-inviato');
        });

        const guestContext = await browser.newContext({ viewport: { width: 390, height: 844 }, locale: 'it-IT', timezoneId: 'Europe/Rome', isMobile: true, hasTouch: true });
        const guest = await guestContext.newPage();

        await test.step('ospite: preventivo con prezzo congelato e dati precompilati', async () => {
            await guest.goto(new URL(link).pathname);
            await expect(guest.getByRole('heading', { name: 'Il tuo preventivo', level: 1 })).toBeVisible();
            await expect(guest.getByTestId('quote-summary')).toContainText('Bilocale Vista Mare');
            await expect(guest.getByTestId('price')).toContainText('Sconto');
            await expect(guest.getByTestId('price-total')).toHaveText(/€/);
            await expect(guest.getByLabel('Nome', { exact: true })).toHaveValue('Marta');
            await expect(guest.getByLabel('Email', { exact: true })).toHaveValue('marta.esempio@example.test');
            await snap(guest, 'ospite-preventivo');
        });

        await test.step('ospite: accetta e paga sulla pagina di pagamento simulato', async () => {
            await guest.getByLabel('Telefono', { exact: true }).fill('+39 333 000 0177');
            await guest.locator('select[name="country_code"]').selectOption('IT');
            await guest.locator('input[name="accept_terms"]').check();
            await guest.locator('input[name="accept_privacy"]').check();
            await guest.getByRole('button', { name: 'Accetta e prosegui al pagamento' }).click();

            await expect(guest.getByRole('heading', { name: 'Pagamento simulato' })).toBeVisible();
            await guest.getByRole('button', { name: 'Pagamento riuscito' }).click();
            await expect(guest.getByTestId('reservation-status')).toHaveAttribute('data-status', 'CONFIRMED', { timeout: 15_000 });
            await snap(guest, 'ospite-preventivo-confermato');
        });

        await test.step('il link non è più pagabile', async () => {
            await guest.goto(new URL(link).pathname);
            await expect(guest.getByTestId('quote-accepted')).toBeVisible();
        });

        await test.step('pannello: preventivo accettato con la prenotazione collegata', async () => {
            await manager.reload();
            await manager.getByTestId('quote-filter').selectOption('ACCEPTED');
            const row = manager.getByTestId('quote-row').first();
            await expect(row).toHaveAttribute('data-status', 'ACCEPTED');
            await expect(row.getByRole('link', { name: /Prenotazione [A-Z0-9]{8}/ })).toBeVisible();
            await snap(manager, 'pannello-preventivo-accettato');
        });

        await guestContext.close();
        await panel.close();
    });
});
