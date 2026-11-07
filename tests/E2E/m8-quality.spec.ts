import { readFileSync, writeFileSync } from 'node:fs';
import { gzipSync } from 'node:zlib';

import { chromium, devices, expect, type Page, test } from '@playwright/test';

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

        await page.getByTestId('unit-card').first().getByRole('link').first().click();
        await expect(page.getByRole('heading', { level: 1 })).toBeVisible();
        await page.locator(`[data-day="${inDays(1)}"] button`).click();
        await page.locator(`[data-day="${inDays(3)}"] button`).click();
        await expect(page.getByTestId('price-total')).toBeVisible();
        await snap(page, 'm8-ospite-alloggio');

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
            await page.locator(`[data-day="${inDays(1)}"] button`).click();
            await page.locator(`[data-day="${inDays(3)}"] button`).click();
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
        test.setTimeout(300_000);

        const { default: lighthouse } = await import('lighthouse');
        const port = 9333;
        const chrome = await chromium.launch({ args: [`--remote-debugging-port=${port}`] });
        const base = process.env.E2E_BASE_URL_OVERRIDE ?? 'http://127.0.0.1:8001';
        const scores: Record<string, Record<string, number>> = {};

        try {
            for (const [key, path] of [['struttura', GUEST_PAGE], ['embed', EMBED_PAGE]] as const) {
                const result = await lighthouse(`${base}${path}`, { port, output: 'json', logLevel: 'error', onlyCategories: ['performance', 'accessibility'] });
                const categories = result?.lhr.categories ?? {};
                scores[key] = {
                    performance: Math.round((categories.performance?.score ?? 0) * 100),
                    accessibility: Math.round((categories.accessibility?.score ?? 0) * 100),
                    lcp_ms: Math.round(Number(result?.lhr.audits['largest-contentful-paint']?.numericValue ?? 0)),
                    tbt_ms: Math.round(Number(result?.lhr.audits['total-blocking-time']?.numericValue ?? 0)),
                    cls: Number(result?.lhr.audits['cumulative-layout-shift']?.numericValue ?? 0),
                };
                writeFileSync(`${SCREENSHOT_DIR}/lighthouse-${key}.json`, typeof result?.report === 'string' ? result.report : '');
            }
        } finally {
            await chrome.close();
        }

        record('lighthouse', scores);
        check((scores.struttura?.performance ?? 0) >= 90 && (scores.struttura?.accessibility ?? 0) >= 95, `Lighthouse pagina ospite: ${JSON.stringify(scores.struttura)}`);
    });
});
