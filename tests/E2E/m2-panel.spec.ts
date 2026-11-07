import { expect, type Locator, type Page, test } from '@playwright/test';

import { snap } from './support/screenshots';
import { secondsLeftInStep, totp } from './support/totp';

/*
 * Verifica di M2 nel browser (AC1, parte pannello): sul database demo appena rigenerato il
 * proprietario demo entra con password e MFA, configura una struttura con i valori del test golden
 * di 04, controlla l'anteprima (1.585,00 €, caparra 475,50 €, saldo 1.109,50 €) e la pubblica.
 * Solo in locale per ora; in CI da M6.
 */

const TENANT = 'demo-alghero';
const OWNER_EMAIL = 'proprietario.demo@example.test';
const PROPERTY = { name: 'Casa Golden E2E', slug: 'casa-golden-e2e' };

function section(page: Page, title: string): Locator {
    return page.locator('section').filter({ has: page.getByRole('heading', { name: title, exact: true }) });
}

/** Gli importi usano lo spazio non separabile prima di €. */
function money(text: string): RegExp {
    return new RegExp(`^${text.replace(/\./g, '\\.').replace(' ', '\\s')}$`);
}

test('M2: configurazione, anteprima golden e pubblicazione dal pannello', async ({ page }) => {
    const password = process.env.E2E_DEMO_PASSWORD;
    expect(password, 'password demo dal global setup').toBeTruthy();

    await test.step('accesso con password e configurazione della MFA', async () => {
        await page.goto('/app');
        await page.getByLabel('Email').fill(OWNER_EMAIL);
        await page.getByLabel('Password').fill(password ?? '');
        await snap(page, 'login');
        await page.getByRole('button', { name: 'Accedi' }).click();

        const secret = (await page.locator('code').textContent({ timeout: 15_000 }))?.trim() ?? '';
        expect(secret).toMatch(/^[A-Z2-7]{16,}$/);
        // Stesso processo di Playwright (un solo worker): lo spec di M3 entra nel pannello con questo segreto.
        process.env.E2E_DEMO_MFA_SECRET = secret;

        if (secondsLeftInStep() < 3) {
            await page.waitForTimeout(secondsLeftInStep() * 1000 + 500);
        }

        await page.getByLabel('Codice a 6 cifre').fill(totp(secret));
        await snap(page, 'mfa-configurazione');
        await page.getByRole('button', { name: 'Attiva' }).click();
        await page.getByLabel('Ho salvato i codici di recupero').check();
        await page.getByRole('button', { name: 'Vai al pannello' }).click();
        await expect(page.getByRole('heading', { name: 'Strutture', level: 1 })).toBeVisible();
    });

    await test.step('struttura con testi IT/EN e politica predefinita', async () => {
        const create = section(page, 'Nuova struttura');
        await create.getByLabel('Nome interno').fill(PROPERTY.name);
        await create.getByLabel('Indirizzo della pagina').fill(PROPERTY.slug);
        await create.getByRole('button', { name: 'Crea struttura' }).click();
        await expect(page.getByRole('heading', { name: PROPERTY.name, level: 1 })).toBeVisible();

        const details = section(page, 'Dati della struttura');
        await details.getByLabel('Città').fill('Alghero');
        // Guardia PRIVACY_POLICY_MISSING (ADR-0011): senza informativa la struttura non si pubblica.
        await details.getByLabel("Indirizzo dell'informativa privacy (https)").fill('https://golden.example.test/privacy');
        await details.getByLabel('Politica di cancellazione predefinita').selectOption({ label: 'Flessibile (7 giorni)' });

        const it = details.locator('fieldset').filter({ hasText: 'Italiano' });
        await it.getByLabel("Nome mostrato all'ospite").fill('Casa Golden');
        await it.getByLabel('Descrizione').fill('Struttura di prova con i valori del test golden.');

        const en = details.locator('fieldset').filter({ hasText: 'Inglese' });
        await en.getByLabel("Nome mostrato all'ospite").fill('Golden House');
        await en.getByLabel('Descrizione').fill('Test property with the golden test values.');

        await details.getByRole('button', { name: 'Salva' }).click();
        await expect(details.getByRole('status')).toHaveText('Salvato.');
        await snap(page, 'struttura-salvata');
    });

    await test.step('unità con i valori del test golden e una foto', async () => {
        const units = section(page, 'Unità');
        await units.getByLabel('Nome interno').fill('Appartamento Golden');
        await units.getByLabel('Ospiti inclusi nel prezzo').fill('4');
        await units.getByLabel('Ospiti massimi').fill('6');
        await units.getByLabel('Prezzo base a notte (€)').fill('120,00');
        await units.getByRole('button', { name: 'Crea unità' }).click();
        await expect(page.getByRole('heading', { name: 'Appartamento Golden', level: 1 })).toBeVisible();

        const details = section(page, "Dati dell'unità");
        await details.getByLabel('Notti minime').fill('3');
        await details.getByLabel('Pulizia finale (€)').fill('60,00');
        await details.getByLabel('Supplemento per ospite in più').fill('15,00');
        await details.getByRole('button', { name: 'Salva' }).click();
        await expect(details.getByRole('status')).toHaveText('Salvato.');

        const photos = section(page, 'Foto');
        await photos.locator('input[type="file"]').setInputFiles('tests/E2E/fixtures/foto-prova.jpg');
        // Etichetta esatta: il testo di aiuto contiene "copertina" in minuscolo.
        await expect(photos.getByText('Copertina', { exact: true })).toBeVisible({ timeout: 30_000 });
        await expect(photos.getByRole('img')).toHaveCount(1);
        await snap(page, 'unita-con-foto');
    });

    await test.step('stagioni Agosto e Ferragosto', async () => {
        const rates = section(page, 'Tariffe');
        const seasons: Array<[string, string, string, string, string, string]> = [
            ['Agosto', '2027-08-01', '2027-08-31', '180,00', '10', '5'],
            ['Ferragosto', '2027-08-13', '2027-08-17', '220,00', '20', ''],
        ];

        for (const [name, first, last, price, priority, minNights] of seasons) {
            await rates.getByRole('button', { name: 'Aggiungi stagione' }).click();
            const form = rates.locator('form').filter({ has: page.getByRole('heading', { name: 'Aggiungi stagione' }) });
            await form.getByLabel('Nome').fill(name);
            await form.getByLabel('Prima notte').fill(first);
            await form.getByLabel('Ultima notte').fill(last);
            await form.getByLabel('Prezzo a notte (€)').fill(price);
            await form.getByLabel('Priorità').fill(priority);
            await form.getByLabel('Notti minime').fill(minNights);
            await form.getByRole('button', { name: 'Salva' }).click();
            await expect(rates.getByRole('cell', { name, exact: true })).toBeVisible();
        }

        await snap(page, 'stagioni');
    });

    await test.step('anteprima 10→17 agosto con 5 ospiti', async () => {
        const preview = section(page, 'Anteprima prezzo');
        await preview.getByLabel('Check-in').fill('2027-08-10');
        await preview.getByLabel('Check-out').fill('2027-08-17');
        await preview.getByLabel('Adulti').fill('4');
        await preview.getByLabel('Bambini').fill('1');
        await preview.getByRole('button', { name: 'Calcola' }).click();

        await expect(preview.getByTestId('price-total')).toHaveText(money('1.585,00 €'));
        await expect(preview.getByTestId('price-deposit')).toHaveText(money('475,50 €'));
        await expect(preview.getByTestId('price-balance')).toHaveText(money('1.109,50 €'));
        await snap(page, 'anteprima-golden');
    });

    await test.step('pubblicazione e pagina pubblica dell\'API', async () => {
        await page.getByRole('link', { name: /Torna alla struttura/ }).click();
        await expect(page.getByRole('heading', { name: PROPERTY.name, level: 1 })).toBeVisible();

        await section(page, 'Pubblicazione').getByRole('button', { name: 'Pubblica' }).click();
        await expect(page.getByTestId('property-status')).toHaveText('Pubblicata');
        await snap(page, 'struttura-pubblicata');

        const response = await page.request.get(`/api/v1/public/${TENANT}/${PROPERTY.slug}?locale=en`);
        expect(response.status()).toBe(200);
        const body = await response.json();
        expect(body.slug).toBe(PROPERTY.slug);
        expect(body.name).toBe('Golden House');
        expect(body.units).toHaveLength(1);
        expect(body.units[0].photos).toHaveLength(1);

        await page.goto(`/api/v1/public/${TENANT}/${PROPERTY.slug}?locale=it`);
        await expect(page.locator('body')).toContainText('Casa Golden');
        await snap(page, 'api-pubblica');
    });
});
