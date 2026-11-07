import { readFileSync, writeFileSync } from 'node:fs';
import { gzipSync } from 'node:zlib';

import { chromium, devices, expect, type Page, test } from '@playwright/test';

import { startGzipProxy } from './support/gzip-proxy';
import { checkAccessibility, SCREENSHOT_DIR, snap } from './support/screenshots';
import { secondsLeftInStep, totp } from './support/totp';

/*
 * M8 "Qualità del frontend" (ADR-0017):
 * - axe (WCAG 2.2 AA) sulle pagine che gli altri spec non attraversano: accesso, pannello pagina per
 *   pagina, pagina ospite, vista embed, preventivo, in italiano e in inglese (8.2);
 * - pagina ospite su iPhone SE, iPhone 15 e Pixel 7: screenshot, niente scorrimento orizzontale, aree
 *   toccabili di almeno 44 px, riepilogo del prezzo raggiungibile (8.3);
 * - budget del JavaScript iniziale della pagina ospite e Lighthouse mobile (8.4; Lighthouse solo con
 *   LIGHTHOUSE=1, perché lento).
 * Gira dopo m2-panel nello stesso processo (MFA del proprietario demo).
 */

const TENANT = 'demo-alghero';
const PROPERTY = 'casa-demo-alghero';
const OWNER_EMAIL = 'proprietario.demo@example.test';
const GUEST_PAGE = `/p/${TENANT}/${PROPERTY}`;
const EMBED_PAGE = `/e/${TENANT}/${PROPERTY}`;

/** Con M8_STRICT=0 i controlli di 8.3 e 8.4 si registrano soltanto (misura "prima"). */
const STRICT = process.env.M8_STRICT !== '0';
const findings: Record<string, unknown> = {};

function record(key: string, value: unknown): void {
    findings[key] = value;
    writeFileSync(`${SCREENSHOT_DIR}/m8-findings.json`, JSON.stringify(findings, null, 2));
}

function check(condition: boolean, message: string): void {
    if (STRICT) {
        expect(condition, message).toBe(true);
    }
}

function inDays(days: number): string {
    const date = new Date();
    date.setDate(date.getDate() + days);

    return `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-${String(date.getDate()).padStart(2, '0')}`;
}

/**
 * Primo arrivo con tre giorni liberi di seguito nel calendario visibile (due notti): le date di domani
 * possono essere occupate dagli spec precedenti, quindi non si dà per scontato nessun giorno.
 */
async function firstFreeStart(page: Page): Promise<number> {
    await expect(page.getByTestId('calendar')).toBeVisible();

    for (let start = 1; start < 50; start++) {
        let free = true;

        for (let day = start; day <= start + 2 && free; day++) {
            const button = page.locator(`[data-day="${inDays(day)}"] button`);
            free = (await button.count()) === 1 && (await button.isEnabled());
        }

        if (free) {
            return start;
        }
    }

    throw new Error('Nessuna data libera nel calendario visibile.');
}

async function signIn(page: Page): Promise<void> {
    await page.goto('/app');
    await page.getByLabel('Email').fill(OWNER_EMAIL);
    await page.getByLabel('Password').fill(process.env.E2E_DEMO_PASSWORD ?? '');
    await page.getByRole('button', { name: 'Accedi' }).click();
    await page.waitForTimeout(secondsLeftInStep() * 1000 + 500);
    await page.getByLabel('Codice a 6 cifre').fill(totp(process.env.E2E_DEMO_MFA_SECRET ?? ''));
    await page.getByRole('button', { name: 'Verifica' }).click();
    await expect(page.getByRole('heading', { name: 'Strutture', level: 1 })).toBeVisible();
}

/** Larghezza del documento oltre il viewport e controlli interattivi visibili più piccoli di 44×44 px. */
async function layoutProblems(page: Page): Promise<{ overflow: number; smallTargets: string[] }> {
    return page.evaluate(() => {
        const overflow = document.documentElement.scrollWidth - window.innerWidth;
        const smallTargets: string[] = [];

        for (const el of Array.from(document.querySelectorAll<HTMLElement>('a[href], button, input, select, textarea, [role="button"]'))) {
            const rect = el.getBoundingClientRect();
            const style = window.getComputedStyle(el);

            if (rect.width === 0 || rect.height === 0 || style.visibility === 'hidden' || el.closest('[aria-hidden="true"]')) {
                continue;
            }

            // Link dentro una frase: eccezione "inline" di WCAG 2.5.8 (dimensione decisa dal testo).
            const parentText = el.parentElement?.textContent?.trim() ?? '';

            if (el.tagName === 'A' && parentText.length > (el.textContent?.trim().length ?? 0) + 10) {
                continue;
            }

            // Caselle e radio: conta l'etichetta che le contiene (area toccabile reale).
            const target = (el instanceof HTMLInputElement && (el.type === 'checkbox' || el.type === 'radio') && el.closest('label')) || el;
            const box = target.getBoundingClientRect();

            if (box.width < 44 || box.height < 44) {
                const text = (el.getAttribute('aria-label') ?? el.textContent ?? el.getAttribute('name') ?? el.tagName).trim().slice(0, 40);
                smallTargets.push(`${el.tagName.toLowerCase()} "${text}" ${Math.round(box.width)}×${Math.round(box.height)}`);
            }
        }

        return { overflow, smallTargets };
    });
}

test.describe.configure({ mode: 'serial' });

test.describe('M8 qualità del frontend', () => {
    test('JavaScript iniziale della pagina ospite entro 200 KB compressi', () => {
        const manifest = JSON.parse(readFileSync('public/build/manifest.json', 'utf8')) as Record<string, { file: string; imports?: string[]; css?: string[] }>;
        const seen = new Set<string>();
        const visit = (key: string) => {
            if (seen.has(key) || manifest[key] === undefined) {
                return;
            }

            seen.add(key);
            (manifest[key]?.imports ?? []).forEach(visit);
        };
        visit('resources/js/guest/main.tsx');

        const files = [...seen].map((key) => manifest[key]?.file ?? '');
        const bytes = files.reduce((sum, file) => sum + gzipSync(readFileSync(`public/build/${file}`)).length, 0);
        record('guest_initial_js_gzip_kb', Math.round(bytes / 102.4) / 10);
        record('guest_initial_js_files', files);
        check(bytes <= 200 * 1024, `JavaScript iniziale della pagina ospite: ${Math.round(bytes / 1024)} KB compressi`);
    });

    test('pagina ospite, vista embed e preventivo: accessibilità in italiano e in inglese', async ({ browser }) => {
        const context = await browser.newContext({ locale: 'it-IT', timezoneId: 'Europe/Rome' });
        const page = await context.newPage();

        await page.goto(GUEST_PAGE);
        await expect(page.getByRole('heading', { level: 1 })).toBeVisible();
        await snap(page, 'm8-ospite-struttura');
        await expect(page.getByTestId('property-contact').getByTestId('property-privacy')).toHaveAttribute('href', /^https:/);

        await page.getByTestId('unit-card').first().getByRole('link').first().click();
        await expect(page.getByRole('heading', { level: 1 })).toBeVisible();
        const start = await firstFreeStart(page);
        await page.locator(`[data-day="${inDays(start)}"] button`).click();
        await page.locator(`[data-day="${inDays(start + 2)}"] button`).click();
        await expect(page.getByTestId('price-total')).toBeVisible();
        await snap(page, 'm8-ospite-alloggio');

        // 8.7: prima del pagamento politica di cancellazione, pagamento sicuro, dati e contatti.
        const before = page.getByTestId('before-payment');
        await expect(before.getByTestId('trust-policy')).toContainText('Cancellazione:');
        await expect(before.getByTestId('trust-payment')).toContainText('Stripe');
        await expect(before.getByTestId('trust-privacy').getByRole('link', { name: 'informativa privacy' })).toHaveAttribute('href', /^https:/);
        await expect(before.getByTestId('trust-contact').getByRole('link').first()).toHaveAttribute('href', /^(mailto|tel):/);
        await before.scrollIntoViewIfNeeded();
        await snap(page, 'm8-ospite-prima-di-pagare');

        // Errore di validazione visibile: invio senza dati.
        await page.getByRole('button', { name: /pagamento|Invia/ }).last().click();
        await checkAccessibility(page, 'm8-ospite-alloggio-errori');

        await page.goto(EMBED_PAGE);
        await expect(page.getByRole('heading', { level: 1 })).toBeVisible();
        await snap(page, 'm8-embed-struttura');

        const english = await browser.newContext({ locale: 'en-GB', timezoneId: 'Europe/Rome' });
        const en = await english.newPage();
        await en.goto(`${GUEST_PAGE}?lang=en`);
        await expect(en.getByRole('heading', { level: 1 })).toBeVisible();
        await snap(en, 'm8-ospite-struttura-en');
        await english.close();
        await context.close();
    });

    test('tastiera: "Vai al contenuto" e scelta delle date con le frecce, annunciata', async ({ browser }) => {
        const context = await browser.newContext({ locale: 'it-IT', timezoneId: 'Europe/Rome' });
        const page = await context.newPage();

        await page.goto(GUEST_PAGE);
        await expect(page.getByRole('heading', { level: 1 })).toBeVisible();
        await page.keyboard.press('Tab');
        await expect(page.getByRole('link', { name: 'Vai al contenuto' })).toBeFocused();
        await page.keyboard.press('Enter');
        await expect(page.locator('main')).toBeFocused();

        await page.getByTestId('unit-card').first().getByRole('link').first().click();
        await expect(page.getByTestId('calendar')).toBeVisible();
        // Il giorno di arrivo ha un nome accessibile in italiano; poi solo tastiera.
        const start = await firstFreeStart(page);
        const first = page.locator(`[data-day="${inDays(start)}"] button`);
        await expect(first).toHaveAccessibleName(/novembre|dicembre|gennaio|febbraio|marzo|aprile|maggio|giugno|luglio|agosto|settembre|ottobre/);
        await first.focus();
        await page.keyboard.press('Enter');
        await page.keyboard.press('ArrowRight');
        await page.keyboard.press('ArrowRight');
        await page.keyboard.press('Enter');
        await expect(page.getByTestId('stay-summary')).toContainText('2 notti');
        await expect(page.getByTestId('stay-summary')).toHaveAttribute('aria-live', 'polite');
        await expect(page.locator(`[data-day="${inDays(start)}"] button`)).toHaveAccessibleName(/arrivo/);

        // Invio con campi vuoti: focus sul primo campo non valido, collegato al messaggio.
        await page.getByRole('button', { name: /Prosegui/ }).click();
        const firstName = page.getByLabel('Nome', { exact: false }).first();
        await expect(firstName).toBeFocused();
        await expect(firstName).toHaveAttribute('aria-invalid', 'true');
        await checkAccessibility(page, 'm8-tastiera-errori');
        await context.close();
    });

    test('galleria: la foto si apre ingrandita e si chiude con Esc', async ({ browser }) => {
        test.skip(process.env.E2E_DEMO_MFA_SECRET === undefined, 'Serve la struttura con foto creata dallo spec di M2.');

        const { defaultBrowserType: _ignored, ...device } = devices['iPhone 15'];
        const context = await browser.newContext({ ...device, locale: 'it-IT', timezoneId: 'Europe/Rome' });
        const page = await context.newPage();
        await page.goto(`/p/${TENANT}/casa-golden-e2e`);
        await page.getByTestId('unit-card').first().getByRole('link').first().click();
        await page.getByTestId('gallery-zoom').click();
        await expect(page.getByTestId('gallery-dialog')).toBeVisible();
        await expect(page.getByRole('button', { name: 'Chiudi' })).toBeFocused();
        await snap(page, 'm8-galleria-ingrandita');
        await page.keyboard.press('Escape');
        await expect(page.getByTestId('gallery-dialog')).toBeHidden();
        await context.close();
    });

    test('pannello: accessibilità pagina per pagina', async ({ browser }) => {
        test.skip(process.env.E2E_DEMO_MFA_SECRET === undefined, 'Serve la MFA configurata dallo spec di M2 nello stesso processo.');

        const anonymous = await browser.newContext({ locale: 'it-IT', timezoneId: 'Europe/Rome' });
        const login = await anonymous.newPage();
        await login.goto('/app/login');
        await expect(login.getByLabel('Email')).toBeVisible();
        await snap(login, 'm8-pannello-accesso');
        await login.goto('/app/password-dimenticata');
        await expect(login.getByRole('heading', { level: 1 })).toBeVisible();
        await snap(login, 'm8-pannello-password-dimenticata');
        await anonymous.close();

        const context = await browser.newContext({ locale: 'it-IT', timezoneId: 'Europe/Rome' });
        await context.grantPermissions(['clipboard-read', 'clipboard-write']);
        const page = await context.newPage();
        await signIn(page);
        await snap(page, 'm8-pannello-strutture');

        const pages: Array<[string, string]> = [
            ['/app/calendario', 'calendario'],
            ['/app/prenotazioni', 'prenotazioni'],
            ['/app/prenotazioni/nuova', 'nuova-prenotazione'],
            ['/app/pagamenti', 'pagamenti'],
            ['/app/preventivi', 'preventivi'],
            ['/app/conflitti', 'conflitti'],
            ['/app/calendari', 'calendari'],
            ['/app/metriche', 'metriche'],
            ['/app/account', 'account'],
            ['/app/uscita', 'uscita'],
            ['/app/accessi-supporto', 'accessi-supporto'],
        ];

        for (const [path, name] of pages) {
            await page.goto(path);
            await expect(page.getByRole('heading', { level: 1 })).toBeVisible();
            await page.waitForLoadState('networkidle');
            await snap(page, `m8-pannello-${name}`);
        }

        await page.goto('/app');
        await page.getByRole('main').getByRole('link').first().click();
        await expect(page.getByRole('heading', { level: 1 })).toBeVisible();
        await page.waitForLoadState('networkidle');
        await snap(page, 'm8-pannello-struttura');

        await page.goto('/app/prenotazioni');
        await page.waitForLoadState('networkidle');
        await page.getByRole('main').getByRole('link').filter({ hasText: /^[A-Z0-9]{8}$/ }).first().click();
        await expect(page.getByRole('heading', { level: 1 })).toBeVisible();
        await page.waitForLoadState('networkidle');
        await snap(page, 'm8-pannello-prenotazione');

        // Preventivo inviato del seed demo: link dalla riga, poi pagina ospite del preventivo.
        await page.goto('/app/preventivi');
        await page.getByTestId('quote-filter').selectOption('SENT');
        const row = page.getByTestId('quote-row').first();
        await row.getByRole('button', { name: 'Copia link' }).click();
        const link = (await row.getByTestId('quote-link').textContent())?.trim() ?? '';
        const guest = await browser.newContext({ locale: 'it-IT', timezoneId: 'Europe/Rome' });
        const quote = await guest.newPage();
        await quote.goto(new URL(link).pathname);
        await expect(quote.getByRole('heading', { level: 1 })).toBeVisible();
        await snap(quote, 'm8-ospite-preventivo');
        await guest.close();

        // 8.5: le azioni irreversibili chiedono conferma nel dialogo condiviso; Annulla non cambia nulla.
        await row.getByRole('button', { name: 'Ritira' }).click();
        const dialog = page.getByTestId('confirm-dialog');
        await expect(dialog).toBeVisible();
        await expect(dialog.getByRole('button', { name: 'Annulla' })).toBeFocused();
        await snap(page, 'm8-pannello-conferma');
        await page.keyboard.press('Escape');
        await expect(dialog).toBeHidden();
        await expect(row.getByRole('button', { name: 'Ritira' })).toBeVisible();
        await context.close();
    });

    for (const name of ['iPhone SE', 'iPhone 15', 'Pixel 7'] as const) {
        test(`telefono ${name}: nessuno scorrimento orizzontale, aree toccabili, prezzo raggiungibile`, async ({ browser }) => {
            const { defaultBrowserType: _ignored, ...device } = devices[name];
            const context = await browser.newContext({ ...device, locale: 'it-IT', timezoneId: 'Europe/Rome' });
            const page = await context.newPage();
            const slug = name.toLowerCase().replace(/\s+/g, '-');

            await page.goto(GUEST_PAGE);
            await expect(page.getByRole('heading', { level: 1 })).toBeVisible();
            const property = await layoutProblems(page);
            await snap(page, `m8-${slug}-struttura`);

            await page.getByTestId('unit-card').first().getByRole('link').first().click();
            await expect(page.getByRole('heading', { level: 1 })).toBeVisible();
            const start = await firstFreeStart(page);
            await page.locator(`[data-day="${inDays(start)}"] button`).click();
            await page.locator(`[data-day="${inDays(start + 2)}"] button`).click();
            await expect(page.getByTestId('price-total')).toBeVisible();
            const booking = await layoutProblems(page);
            await page.evaluate(() => window.scrollTo(0, 0));
            const summaryVisible = await page.getByTestId('price-summary-bar').isVisible().catch(() => false);
            await snap(page, `m8-${slug}-alloggio`);

            record(`phone_${slug}`, { property, booking, summaryVisible });
            check(property.overflow <= 0 && booking.overflow <= 0, `${name}: scorrimento orizzontale`);
            check(property.smallTargets.length === 0 && booking.smallTargets.length === 0, `${name}: aree toccabili sotto 44 px: ${[...property.smallTargets, ...booking.smallTargets].join(', ')}`);
            check(summaryVisible, `${name}: riepilogo del prezzo non visibile in cima alla pagina`);
            await context.close();
        });
    }

    test('Lighthouse mobile della pagina ospite', async () => {
        test.skip(process.env.LIGHTHOUSE !== '1', 'Solo con LIGHTHOUSE=1.');
        test.setTimeout(600_000);

        const { default: lighthouse } = await import('lighthouse');
        const port = 9333;
        const proxyPort = 8002;
        const target = new URL('http://127.0.0.1:8001');
        // Due misure: dal server di sviluppo (php -S, senza compressione) e dal proxy che comprime come in
        // produzione. Le soglie di 8.4 valgono sulla seconda.
        const proxy = await startGzipProxy(target, proxyPort);
        const chrome = await chromium.launch({ args: [`--remote-debugging-port=${port}`] });
        const scores: Record<string, Record<string, number>> = {};

        try {
            for (const [server, base] of [['senza_compressione', target.origin], ['compresso', `http://127.0.0.1:${proxyPort}`]] as const) {
                for (const [key, path] of [['struttura', GUEST_PAGE], ['embed', EMBED_PAGE]] as const) {
                    const result = await lighthouse(`${base}${path}`, { port, output: 'json', logLevel: 'error', onlyCategories: ['performance', 'accessibility'] });
                    const categories = result?.lhr.categories ?? {};
                    scores[`${server}_${key}`] = {
                        performance: Math.round((categories.performance?.score ?? 0) * 100),
                        accessibility: Math.round((categories.accessibility?.score ?? 0) * 100),
                        lcp_ms: Math.round(Number(result?.lhr.audits['largest-contentful-paint']?.numericValue ?? 0)),
                        tbt_ms: Math.round(Number(result?.lhr.audits['total-blocking-time']?.numericValue ?? 0)),
                        cls: Number(result?.lhr.audits['cumulative-layout-shift']?.numericValue ?? 0),
                    };
                    writeFileSync(`${SCREENSHOT_DIR}/lighthouse-${server}-${key}.json`, typeof result?.report === 'string' ? result.report : '');
                }
            }
        } finally {
            await chrome.close();
            proxy.close();
        }

        record('lighthouse', scores);
        const guest = scores.compresso_struttura;
        check((guest?.performance ?? 0) >= 90 && (guest?.accessibility ?? 0) >= 95, `Lighthouse pagina ospite (compressa): ${JSON.stringify(guest)}`);
    });
});
