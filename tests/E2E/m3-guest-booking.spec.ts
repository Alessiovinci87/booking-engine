import { expect, type Locator, type Page, test } from '@playwright/test';

import { snap } from './support/screenshots';
import { secondsLeftInStep, totp } from './support/totp';

/*
 * Verifica di M3 nel browser, sul database demo appena rigenerato (struttura pubblicata con
 * prenotazioni di esempio, gateway finto):
 * 1. l'ospite sceglie un alloggio dal telefono, le date sul calendario, vede il prezzo con le righe
 *    dell'API, compila i dati, accetta condizioni e informativa e prosegue al pagamento simulato;
 *    abbandona, riprende dalla pagina di ritorno e paga; la conferma arriva dal servizio di
 *    pagamento (I6), la pagina la mostra dopo il polling;
 * 2. il gestore ritrova la prenotazione nel pannello (elenco, dettaglio, calendario).
 * Solo in locale per ora; in CI da M6.
 */

const TENANT = 'demo-alghero';
const PROPERTY = 'casa-demo-alghero';
const OWNER_EMAIL = 'proprietario.demo@example.test';
const GUEST_PAGE = `/p/${TENANT}/${PROPERTY}`;

/** "YYYY-MM-DD" fra `days` giorni, nel fuso del browser (Europe/Rome nella configurazione). */
function inDays(days: number): string {
    const date = new Date();
    date.setDate(date.getDate() + days);

    return `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-${String(date.getDate()).padStart(2, '0')}`;
}

function section(page: Page, title: string): Locator {
    return page.locator('section').filter({ has: page.getByRole('heading', { name: title, exact: true }) });
}

let reservationCode = '';

test.describe.configure({ mode: 'serial' });

test.describe('M3', () => {
    test('l\'ospite prenota dal telefono e la conferma arriva dal pagamento simulato', async ({ browser }) => {
        const context = await browser.newContext({ viewport: { width: 390, height: 844 }, locale: 'it-IT', timezoneId: 'Europe/Rome', isMobile: true, hasTouch: true });
        const page = await context.newPage();
        // Le notti seminate dal seeder partono da oggi + 5: oggi + 1 → oggi + 3 sono libere (minimo 2 notti).
        const checkIn = inDays(1);
        const checkOut = inDays(3);

        await test.step('pagina della struttura con galleria e schede degli alloggi', async () => {
            await page.goto(GUEST_PAGE);
            await expect(page.getByRole('heading', { name: 'Casa Demo Alghero', level: 1 })).toBeVisible();
            await expect(page.getByTestId('unit-card')).toHaveCount(2);
            await snap(page, 'ospite-struttura');
        });

        await test.step('calendario, ospiti e prezzo con le righe dell\'API', async () => {
            await page.getByTestId('unit-card').filter({ hasText: 'Bilocale Vista Mare' }).getByRole('link', { name: 'Scegli le date' }).click();
            await expect(page.getByRole('heading', { name: /Bilocale Vista Mare/, level: 1 })).toBeVisible();

            // Notte occupata dal seeder (Giulia Ferri, oggi + 5): non selezionabile.
            await expect(page.locator(`[data-day="${inDays(5)}"] button`)).toBeDisabled();

            await page.locator(`[data-day="${checkIn}"] button`).click();
            await page.locator(`[data-day="${checkOut}"] button`).click();
            await expect(page.getByText('2 notti')).toBeVisible();

            const price = page.getByTestId('price');
            await expect(price).toBeVisible();
            await expect(price.getByText('Pulizia finale')).toBeVisible();
            await expect(page.getByTestId('price-total')).toHaveText(/€/);
            await snap(page, 'ospite-date-e-prezzo');
        });

        const total = (await page.getByTestId('price-total').textContent())?.trim() ?? '';

        await test.step('dati dell\'ospite, avviso sulle note, accettazioni', async () => {
            await page.getByLabel('Nome', { exact: true }).fill('Chiara');
            await page.getByLabel('Cognome', { exact: true }).fill('Prova');
            await page.getByLabel('Email', { exact: true }).fill('chiara.prova@example.test');
            await page.getByLabel('Telefono', { exact: true }).fill('+39 333 000 0199');
            await page.locator('select[name="country_code"]').selectOption('IT');
            await page.getByLabel('Note per il gestore (facoltative)').fill('Arrivo in serata.');
            await expect(page.getByTestId('notes-warning')).toBeVisible();

            // Senza accettazioni il modulo non parte.
            await page.getByRole('button', { name: 'Prosegui al pagamento' }).click();
            await expect(page.getByText('Controlla i campi evidenziati.')).toBeVisible();

            await page.locator('input[name="accept_terms"]').check();
            await page.locator('input[name="accept_privacy"]').check();
            await snap(page, 'ospite-dati');
        });

        await test.step('pagamento a pagina intera, abbandono e ripresa', async () => {
            await page.getByRole('button', { name: 'Prosegui al pagamento' }).click();
            await expect(page.getByRole('heading', { name: 'Pagamento simulato' })).toBeVisible();
            await snap(page, 'pagamento-simulato');

            await page.getByRole('button', { name: 'Abbandona' }).click();
            await expect(page).toHaveURL(/\/prenotazione\/[A-Z0-9]{8}\?cancelled=1/);
            await expect(page.getByText('Hai annullato il pagamento')).toBeVisible();
            reservationCode = /prenotazione\/([A-Z0-9]{8})/.exec(page.url())?.[1] ?? '';
            await snap(page, 'ospite-pagamento-annullato');

            await page.getByRole('link', { name: 'Riprendi il pagamento' }).click();
            await expect(page.getByRole('heading', { name: 'Pagamento simulato' })).toBeVisible();
            await page.getByRole('button', { name: 'Pagamento riuscito' }).click();
        });

        await test.step('pagina di ritorno: conferma dal servizio di pagamento', async () => {
            await expect(page).toHaveURL(new RegExp(`/prenotazione/${reservationCode}\\?session=`));
            await expect(page.getByTestId('reservation-status')).toHaveAttribute('data-status', 'CONFIRMED', { timeout: 15_000 });
            await expect(page.getByText('Prenotazione confermata')).toBeVisible();
            await expect(page.getByText(total).first()).toBeVisible();
            await snap(page, 'ospite-confermata');
        });

        await test.step('nessuna disponibilità sulle stesse date', async () => {
            const response = await page.request.get(`/api/v1/public/${TENANT}/${PROPERTY}?locale=it`);
            const unit = ((await response.json()) as { units: { id: string; name: string }[] }).units.find((u) => u.name === 'Bilocale Vista Mare');
            const availability = await page.request.get(`/api/v1/public/${TENANT}/${PROPERTY}/availability?unit_id=${unit?.id}&from=${checkIn}&to=${checkOut}`);
            expect(availability.status(), await availability.text()).toBe(200);
            expect(((await availability.json()) as { unavailable_nights: string[] }).unavailable_nights).toEqual([checkIn, inDays(2)]);
        });

        await test.step('pagina in inglese', async () => {
            await page.goto(GUEST_PAGE);
            await page.getByLabel('Lingua').selectOption('en');
            await expect(page.getByRole('heading', { name: 'Accommodation' })).toBeVisible();
            await snap(page, 'ospite-inglese');
        });

        await context.close();
    });

    test('il gestore ritrova la prenotazione nel pannello', async ({ page }) => {
        const password = process.env.E2E_DEMO_PASSWORD;
        const secret = process.env.E2E_DEMO_MFA_SECRET;
        test.skip(secret === undefined || reservationCode === '', 'Serve la MFA configurata dallo spec di M2 nello stesso processo.');

        await test.step('accesso con password e MFA', async () => {
            await page.goto('/app');
            await page.getByLabel('Email').fill(OWNER_EMAIL);
            await page.getByLabel('Password').fill(password ?? '');
            await page.getByRole('button', { name: 'Accedi' }).click();
            // Codice nuovo: quello dello spec di M2 potrebbe essere già stato usato (anti-replay).
            await page.waitForTimeout(secondsLeftInStep() * 1000 + 500);
            await page.getByLabel('Codice a 6 cifre').fill(totp(secret ?? ''));
            await page.getByRole('button', { name: 'Verifica' }).click();
            await expect(page.getByRole('heading', { name: 'Strutture', level: 1 })).toBeVisible();
        });

        await test.step('elenco con filtro per codice', async () => {
            await page.getByRole('link', { name: 'Prenotazioni' }).click();
            await expect(page.getByTestId('reservations-table')).toBeVisible();
            await snap(page, 'pannello-elenco');
            await page.getByLabel('Codice prenotazione').fill(reservationCode);
            await page.getByRole('button', { name: 'Filtra' }).click();
            await expect(page.getByTestId('reservations-table').locator('tbody tr')).toHaveCount(1);
        });

        await test.step('dettaglio con righe, pagamenti, storico e azioni', async () => {
            await page.getByRole('link', { name: reservationCode }).click();
            await expect(page.getByTestId('reservation-code')).toHaveText(reservationCode);
            await expect(page.getByTestId('reservation-status').first()).toHaveText('Confermata');
            await expect(section(page, 'Pagamenti').getByText('Caparra online')).toBeVisible();
            await expect(page.getByTestId('history')).toContainText('Condizioni e informativa accettate');
            await expect(page.getByTestId('action-cancel')).toBeVisible();
            await expect(page.getByTestId('action-modify')).toBeVisible();
            await snap(page, 'pannello-dettaglio');
        });

        await test.step('pagamento offline del saldo', async () => {
            await page.getByTestId('action-offline_payment').click();
            await page.getByTestId('action-form').getByRole('button', { name: 'Registra pagamento offline' }).click();
            await expect(section(page, 'Pagamenti').getByText('Bonifico')).toBeVisible();
            await expect(page.getByTestId('action-offline_payment')).toHaveCount(0);
        });

        await test.step('calendario con prenotazioni e blocco', async () => {
            await page.getByRole('link', { name: 'Calendario' }).click();
            await page.getByLabel('Unità').selectOption({ label: 'Bilocale Vista Mare' });
            await expect(page.getByTestId('calendar-reservation').filter({ hasText: 'Chiara Prova' }).first()).toBeVisible();
            await snap(page, 'pannello-calendario');
        });
    });
});
