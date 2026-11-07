import { type APIRequestContext, expect, type Page, test } from '@playwright/test';

import { runOutbox } from './support/artisan';
import { snap } from './support/screenshots';
import { secondsLeftInStep, totp } from './support/totp';

/*
 * 6.6 — Criteri di accettazione di 01 nel browser, sul database demo appena rigenerato e con il gateway
 * finto (PAYMENT_GATEWAY=fake). Copre i flussi del pannello e dell'ospite che girano in locale:
 * - AC3 richiesta approvata dal gestore → confermata;
 * - AC4 richiesta rifiutata → date libere ed email all'ospite (casella /dev/mail, 6.1);
 * - AC5 due prenotazioni simultanee delle stesse date → una sola riesce;
 * - AC6/AC13 /app/conflitti (presa in carico e chiusura) e /app/calendari (importati, feed, checklist);
 * - AC2 feed esportato con UID stabili e senza le date della stessa OTA (I18);
 * - AC9 cancellazione con rimborso, conferma della password ed email all'ospite;
 * - AC17 prenotazione manuale senza pagamento, poi bonifico registrato;
 * - I22 anonimizzazione dell'ospite dal pannello, metriche ed export CSV (6.2, 6.3).
 * I passi con Stripe vero (pagamento, incasso, rimborso eseguiti da Stripe, webhook firmati) qui usano il
 * gateway finto: sono "da ripetere su staging" (rapporto M6, tabella degli E2E).
 * Serve la MFA configurata dallo spec di M2 nello stesso processo: npx playwright test m2-panel m6-acceptance
 */

const TENANT = 'demo-alghero';
const PROPERTY = 'casa-demo-alghero';
const OWNER_EMAIL = 'proprietario.demo@example.test';
const GUEST_PAGE = `/p/${TENANT}/${PROPERTY}`;

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

async function openReservation(page: Page, guestName: string): Promise<string> {
    await page.getByRole('link', { name: 'Prenotazioni', exact: true }).click();
    const row = page.getByTestId('reservations-table').locator('tbody tr').filter({ hasText: guestName }).first();
    await expect(row).toBeVisible();
    await row.getByRole('link').first().click();
    const code = page.getByTestId('reservation-code');
    await expect(code).toHaveText(/^[A-Z0-9]{8}$/);

    return (await code.textContent())?.trim() ?? '';
}

/** Lo stato arriva dal servizio di pagamento (I6): si ricarica finché il dettaglio lo mostra. */
async function expectStatus(page: Page, label: string): Promise<void> {
    await expect(async () => {
        await page.reload();
        await expect(page.getByTestId('reservation-status').first()).toHaveText(label, { timeout: 2_000 });
    }).toPass({ timeout: 20_000 });
}

async function reauth(page: Page): Promise<void> {
    const prompt = page.getByTestId('reauth');
    await expect(prompt).toBeVisible();
    await prompt.getByLabel('Password').fill(process.env.E2E_DEMO_PASSWORD ?? '');
    await prompt.getByRole('button', { name: 'Conferma e continua' }).click();
}

/** Email nella casella di anteprima (solo APP_ENV=local) dopo un giro dell'outbox. */
async function expectMail(page: Page, to: string, category: string, code: string): Promise<void> {
    runOutbox();
    await page.goto('/dev/mail');
    const item = page.getByTestId('dev-mail-item').filter({ hasText: to }).filter({ hasText: category }).filter({ hasText: code });
    await expect(item.first()).toBeVisible();
    await item.first().click();
    await expect(page.getByTestId('dev-mail-message')).toContainText(code);
}

type PublicProperty = { units: { id: string; name: string }[]; terms_version: string | null; privacy_version: string };

async function publicBooking(request: APIRequestContext, unitName: string, checkIn: string, checkOut: string, email: string) {
    const property = (await (await request.get(`/api/v1/public/${TENANT}/${PROPERTY}?locale=it`)).json()) as PublicProperty;
    const unit = property.units.find((u) => u.name === unitName);
    const price = (await (await request.post(`/api/v1/public/${TENANT}/${PROPERTY}/quote-price`, {
        data: { unit_id: unit?.id, check_in: checkIn, check_out: checkOut, adults: 2, children: 0 },
    })).json()) as { total: { amount_cents: number } };

    return request.post(`/api/v1/public/${TENANT}/${PROPERTY}/reservations`, {
        headers: { 'Idempotency-Key': crypto.randomUUID(), Accept: 'application/json' },
        data: {
            unit_id: unit?.id, check_in: checkIn, check_out: checkOut, adults: 2, children: 0,
            guest: { first_name: 'Prova', last_name: 'Parallela', email, phone: '+39 333 000 0150', country_code: 'IT', notes: null },
            locale: 'it',
            accepted_terms_version: property.terms_version,
            accepted_privacy_version: property.privacy_version,
            expected_total_cents: price.total.amount_cents,
        },
    });
}

test.describe.configure({ mode: 'serial' });

test.describe('M6 criteri di accettazione', () => {
    test.beforeEach(() => {
        test.skip(process.env.E2E_DEMO_MFA_SECRET === undefined, 'Serve la MFA configurata dallo spec di M2 nello stesso processo.');
    });

    test('AC3 e AC4: richieste su richiesta approvate e rifiutate, con email all\'ospite', async ({ browser }) => {
        const guestContext = await browser.newContext({ viewport: { width: 390, height: 844 }, locale: 'it-IT', timezoneId: 'Europe/Rome', isMobile: true, hasTouch: true });
        const guest = await guestContext.newPage();

        await test.step('ospite: richiesta sul Trilocale (su richiesta), autorizzazione simulata', async () => {
            await guest.goto(GUEST_PAGE);
            await guest.getByTestId('unit-card').filter({ hasText: 'Trilocale Giardino' }).getByRole('link', { name: 'Scegli le date' }).click();
            await guest.locator(`[data-day="${inDays(50)}"] button`).click();
            await guest.locator(`[data-day="${inDays(53)}"] button`).click();
            await expect(guest.getByTestId('price-total')).toHaveText(/€/);
            await guest.getByLabel('Nome', { exact: true }).fill('Rita');
            await guest.getByLabel('Cognome', { exact: true }).fill('Esempio');
            await guest.getByLabel('Email', { exact: true }).fill('rita.esempio@example.test');
            await guest.getByLabel('Telefono', { exact: true }).fill('+39 333 000 0160');
            await guest.locator('select[name="country_code"]').selectOption('IT');
            await guest.locator('input[name="accept_terms"]').check();
            await guest.locator('input[name="accept_privacy"]').check();
            await guest.getByRole('button', { name: 'Invia la richiesta e autorizza il pagamento' }).click();
            await expect(guest.getByRole('heading', { name: 'Pagamento simulato' })).toBeVisible();
            await guest.getByRole('button', { name: 'Pagamento riuscito' }).click();
            await expect(guest.getByTestId('reservation-status')).toHaveAttribute('data-status', 'AWAITING_APPROVAL', { timeout: 15_000 });
            await snap(guest, 'm6-ospite-richiesta-inviata');
        });

        const panel = await browser.newContext({ locale: 'it-IT', timezoneId: 'Europe/Rome' });
        const manager = await panel.newPage();
        await signIn(manager);

        await test.step('AC3: il gestore approva la richiesta di Hans Weber → confermata', async () => {
            await openReservation(manager, 'Hans Weber');
            await expect(manager.getByTestId('reservation-status').first()).toHaveText('Da approvare');
            await manager.getByTestId('action-approve').click();
            await manager.getByTestId('action-form').getByRole('button', { name: 'Conferma' }).click();
            await expectStatus(manager, 'Confermata');
            await snap(manager, 'm6-richiesta-approvata');
        });

        let code = '';

        await test.step('AC4: il gestore rifiuta la richiesta di Rita Esempio → rifiutata', async () => {
            code = await openReservation(manager, 'Rita Esempio');
            await manager.getByTestId('action-reject').click();
            await manager.getByTestId('action-form').getByRole('button', { name: 'Rifiuta' }).click();
            await expectStatus(manager, 'Rifiutata');
        });

        await test.step('AC4: le date tornano libere e l\'ospite riceve l\'email di rifiuto', async () => {
            const property = (await (await manager.request.get(`/api/v1/public/${TENANT}/${PROPERTY}?locale=it`)).json()) as PublicProperty;
            const unit = property.units.find((u) => u.name === 'Trilocale Giardino');
            const availability = await manager.request.get(`/api/v1/public/${TENANT}/${PROPERTY}/availability?unit_id=${unit?.id}&from=${inDays(50)}&to=${inDays(53)}`);
            expect(((await availability.json()) as { unavailable_nights: string[] }).unavailable_nights).toEqual([]);

            await expectMail(manager, 'rita.esempio@example.test', 'reservation.rejected', code);
            await snap(manager, 'm6-email-rifiuto');
        });

        await test.step('I22: anonimizzazione dell\'ospite della richiesta rifiutata (con password)', async () => {
            await manager.goto('/app');
            await openReservation(manager, 'Rita Esempio');
            await expect(manager.getByTestId('guest-export')).toBeVisible();
            await manager.getByTestId('guest-erase').click();
            await manager.getByTestId('guest-erase-confirm').click();
            await reauth(manager);
            await expect(manager.getByText('Dati dell\'ospite anonimizzati.')).toBeVisible();
            await expect(manager.getByText('Rita')).toHaveCount(0);
            await snap(manager, 'm6-ospite-anonimizzato');
        });

        await guestContext.close();
        await panel.close();
    });

    test('AC5: due prenotazioni simultanee delle stesse date, una sola riesce', async ({ request }) => {
        const [first, second] = await Promise.all([
            publicBooking(request, 'Bilocale Vista Mare', inDays(70), inDays(73), 'parallela.uno@example.test'),
            publicBooking(request, 'Bilocale Vista Mare', inDays(70), inDays(73), 'parallela.due@example.test'),
        ]);
        const statuses = [first.status(), second.status()].sort();
        expect(statuses, `${await first.text()} ${await second.text()}`).toEqual([201, 409]);
        const loser = first.status() === 409 ? first : second;
        expect(((await loser.json()) as { error: { code: string } }).error.code).toBe('DATES_UNAVAILABLE');
    });

    test('AC6, AC13 e AC2: conflitti, calendari OTA e feed esportati', async ({ page }) => {
        await signIn(page);

        await test.step('AC6: conflitto Booking contro prenotazione manuale, preso in carico e chiuso', async () => {
            await page.getByRole('link', { name: 'Conflitti' }).click();
            await expect(page.getByRole('heading', { name: 'Conflitti di calendario', level: 1 })).toBeVisible();
            const row = page.getByTestId('conflict-row').filter({ hasText: 'Trilocale Giardino' }).first();
            await expect(row).toHaveAttribute('data-status', 'OPEN');
            await expect(row.getByRole('link', { name: /Prenotazione [A-Z0-9]{8}/ })).toBeVisible();
            await snap(page, 'm6-conflitto-aperto');

            await row.getByRole('button', { name: 'Prendi in carico' }).click();
            await expect(row).toHaveAttribute('data-status', 'INVESTIGATING');
            await row.getByRole('button', { name: 'Risolvi' }).click();
            await row.getByTestId('conflict-form').locator('select').selectOption('CANCELLED_EXTERNAL');
            await row.getByRole('button', { name: 'Chiudi come risolto' }).click();
            await expect(page.getByTestId('conflict-row')).toHaveCount(0);

            await page.getByRole('button', { name: 'Mostra quelli chiusi' }).click();
            await expect(page.getByTestId('conflict-row').first()).toHaveAttribute('data-status', 'RESOLVED');
            await snap(page, 'm6-conflitto-chiuso');
        });

        await test.step('AC13: calendari importati dimostrativi, feed per destinazione, checklist', async () => {
            await page.getByRole('link', { name: 'Calendari OTA' }).click();
            await expect(page.getByRole('heading', { name: 'Calendari OTA', level: 1 })).toBeVisible();
            await page.getByLabel('Unità').selectOption({ label: 'Trilocale Giardino' });
            await expect(page.getByTestId('external-calendar-row')).toHaveCount(2);
            await expect(page.getByTestId('external-calendar-row').first()).toContainText('Calendario dimostrativo');
            await expect(page.getByTestId('feed-row')).toHaveCount(2);
            await expect(page.getByTestId('feed-row').first()).toHaveAttribute('data-status', 'ACTIVE');
            await expect(page.getByRole('heading', { name: 'Checklist di collegamento' })).toBeVisible();
            await snap(page, 'm6-calendari');
        });

        await test.step('AC2, AC13 e I18: UID stabili, date di Booking solo nel feed per Airbnb, nessuna eco', async () => {
            const feed = page.locator('[data-testid="feed-row"][data-destination="AIRBNB"]').first();
            const url = ((await feed.locator('p.break-all').textContent()) ?? '').trim();
            expect(url).toMatch(/^https?:\/\/.+\.ics$/);

            const first = await page.request.get(new URL(url).pathname);
            expect(first.status()).toBe(200);
            const body = await first.text();
            expect(body).toContain('BEGIN:VCALENDAR');
            expect(body).toMatch(/UID:occ-[0-9a-f-]+@/);
            expect(body).not.toContain('airbnb.example.test');
            expect(body).not.toMatch(/Weber|Deiana|@example\.test/); // nessun dato dell'ospite nel feed (I17)

            // AC13: la prenotazione importata da Booking (giorni 18–21) è nel feed per Airbnb, non in quello per Booking.
            const bookingEvent = `DTSTART;VALUE=DATE:${inDays(18).replaceAll('-', '')}`;
            expect(body).toContain(bookingEvent);
            const bookingFeedUrl = ((await page.locator('[data-testid="feed-row"][data-destination="BOOKING"]').first().locator('p.break-all').textContent()) ?? '').trim();
            const bookingFeed = await (await page.request.get(new URL(bookingFeedUrl).pathname)).text();
            expect(bookingFeed).toContain('BEGIN:VCALENDAR');
            expect(bookingFeed).not.toContain(bookingEvent);
            expect(bookingFeed).not.toContain('booking.example.test');

            const uids = (text: string) => [...text.matchAll(/^UID:(.+)$/gm)].map((m) => m[1]?.trim()).sort();
            const second = await page.request.get(new URL(url).pathname);
            expect(uids(await second.text())).toEqual(uids(body));
        });
    });

    test('AC17 e AC9: prenotazione manuale con bonifico; cancellazione con rimborso ed email', async ({ page }) => {
        await signIn(page);

        await test.step('AC17: prenotazione manuale confermata con bonifico registrato', async () => {
            await page.getByRole('link', { name: 'Prenotazioni', exact: true }).click();
            await page.getByRole('link', { name: 'Nuova prenotazione manuale' }).click();
            const form = page.getByTestId('manual-reservation-form');
            await form.getByLabel('Unità').selectOption({ label: 'Bilocale Vista Mare' });
            await form.getByLabel('Check-in').fill(inDays(80));
            await form.getByLabel('Check-out').fill(inDays(83));
            await expect(page.getByTestId('manual-price')).toContainText('€');
            await form.getByLabel('Nome', { exact: true }).fill('Bruno');
            await form.getByLabel('Cognome', { exact: true }).fill('Telefono');
            await form.getByLabel('Email', { exact: true }).fill('bruno.telefono@example.test');
            await form.getByLabel('Telefono', { exact: true }).fill('+39 333 000 0170');
            await snap(page, 'm6-manuale-modulo');
            await form.getByRole('button', { name: 'Crea la prenotazione' }).click();

            // Senza pagamento: confermata e "Non pagata".
            await expect(page.getByTestId('reservation-status').first()).toHaveText('Confermata');
            await expect(page.getByText('Non pagata', { exact: true })).toBeVisible();

            // Bonifico ricevuto per l'intero importo ancora da incassare: risulta pagata.
            await page.getByTestId('action-offline_payment').click();
            await page.getByTestId('action-form').getByLabel('Metodo').selectOption('BANK_TRANSFER');
            await page.getByTestId('action-form').getByRole('button', { name: 'Registra pagamento offline' }).click();
            await expect(page.getByTestId('payments')).toContainText('Bonifico');
            await expect(page.getByText('Pagata', { exact: true })).toBeVisible();
            await snap(page, 'm6-manuale-bonifico');
        });

        let code = '';

        await test.step('AC9: cancellazione della prenotazione approvata con rimborso e password', async () => {
            code = await openReservation(page, 'Hans Weber');
            await page.getByTestId('action-cancel').click();
            await page.getByTestId('action-form').getByLabel('Rimborso online').fill('50,00');
            await page.getByTestId('action-form').getByRole('button', { name: 'Cancella la prenotazione' }).click();
            await reauth(page);
            await expect(page.getByTestId('reservation-status').first()).toHaveText('Cancellata');
            await expect(page.getByTestId('payments')).toContainText('50,00');
        });

        await test.step('AC9: email di cancellazione all\'ospite (in inglese)', async () => {
            await expectMail(page, 'hans.weber@example.test', 'reservation.cancelled', code);
        });

        await test.step('6.2: metriche del periodo ed export CSV', async () => {
            await page.goto('/app/metriche');
            await expect(page.getByRole('heading', { name: 'Metriche', level: 1 })).toBeVisible();
            await expect(page.getByTestId('metric-revenue')).toContainText('Fatturato diretto');
            const download = page.waitForEvent('download');
            await page.getByRole('link', { name: 'Scarica CSV prenotazioni' }).click();
            expect((await download).suggestedFilename()).toMatch(/\.csv$/);
            await snap(page, 'm6-metriche');
        });
    });
});
