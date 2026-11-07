import { expect, type FrameLocator, type Page, test } from '@playwright/test';

import { snap } from './support/screenshots';

/*
 * Verifica di M4 nel browser con Stripe Checkout vero in modalità test (05, 08): l'ospite prenota, va
 * sulla pagina di Stripe a pagina intera, paga con le carte di prova e torna sulla pagina di ritorno,
 * che mostra la conferma solo quando arriva il webhook (I6).
 *
 * Non parte con npm run test:e2e normale. Preparazione (docs/reports/M4-rapporto.md, "Da fare domattina"):
 * 1. account connesso con onboarding completato (php artisan stripe:demo-account, poi il link);
 * 2. in un altro terminale: stripe listen --forward-connect-to http://127.0.0.1:8001/webhooks/stripe
 *    con STRIPE_WEBHOOK_SECRET in .env uguale al segreto stampato da --print-secret;
 * 3. $env:E2E_PAYMENT_GATEWAY='stripe'; $env:E2E_STRIPE_ACCOUNT='acct_…'; npx playwright test m4-stripe-checkout
 *
 * Carte: 4242 4242 4242 4242 (pagamento riuscito), 4000 0025 0000 3155 (3D Secure), 4000 0000 0000 9995
 * (rifiutata per fondi insufficienti).
 */

const TENANT = 'demo-alghero';
const PROPERTY = 'casa-demo-alghero';
const GUEST_PAGE = `/p/${TENANT}/${PROPERTY}`;

function inDays(days: number): string {
    const date = new Date();
    date.setDate(date.getDate() + days);

    return `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-${String(date.getDate()).padStart(2, '0')}`;
}

test.describe.configure({ mode: 'serial' });

test.skip(
    process.env.E2E_PAYMENT_GATEWAY !== 'stripe' || process.env.E2E_STRIPE_ACCOUNT === undefined,
    'Spec con Stripe in modalità test: servono E2E_PAYMENT_GATEWAY=stripe, E2E_STRIPE_ACCOUNT e stripe listen.',
);

/** Dalla pagina della struttura fino alla pagina di Stripe Checkout. */
async function bookAndOpenCheckout(page: Page, checkIn: string, checkOut: string, email: string): Promise<void> {
    await page.goto(GUEST_PAGE);
    await page.getByTestId('unit-card').filter({ hasText: 'Bilocale Vista Mare' }).getByRole('link', { name: 'Scegli le date' }).click();
    await page.locator(`[data-day="${checkIn}"] button`).click();
    await page.locator(`[data-day="${checkOut}"] button`).click();
    await expect(page.getByTestId('price-total')).toHaveText(/€/);

    await page.getByLabel('Nome', { exact: true }).fill('Chiara');
    await page.getByLabel('Cognome', { exact: true }).fill('Prova');
    await page.getByLabel('Email', { exact: true }).fill(email);
    await page.getByLabel('Telefono', { exact: true }).fill('+39 333 000 0199');
    await page.locator('select[name="country_code"]').selectOption('IT');
    await page.locator('input[name="accept_terms"]').check();
    await page.locator('input[name="accept_privacy"]').check();
    await page.getByRole('button', { name: 'Prosegui al pagamento' }).click();

    // Pagina intera di Stripe, mai in un iframe (08).
    await page.waitForURL(/^https:\/\/checkout\.stripe\.com\//, { timeout: 30_000 });
}

async function fillCard(page: Page, number: string): Promise<void> {
    await page.locator('#cardNumber').fill(number);
    await page.locator('#cardExpiry').fill('12 / 34');
    await page.locator('#cardCvc').fill('123');
    await page.locator('#billingName').fill('Chiara Prova');

    const country = page.locator('#billingCountry');

    if (await country.isVisible()) {
        await country.selectOption('IT');
    }

    await page.getByTestId('hosted-payment-submit-button').click();
}

async function expectConfirmedOnReturn(page: Page): Promise<void> {
    await page.waitForURL(/\/prenotazione\/[A-Z0-9]{8}\?session=/, { timeout: 60_000 });
    // La conferma arriva dal webhook inoltrato da stripe listen: la pagina interroga lo stato.
    await expect(page.getByTestId('reservation-status')).toHaveAttribute('data-status', 'CONFIRMED', { timeout: 45_000 });
}

function threeDsChallenge(page: Page): FrameLocator {
    return page.frameLocator('iframe[name^="__privateStripeFrame"]').first().frameLocator('#challengeFrame');
}

test.describe('M4 con Stripe', () => {
    test('carta 4242: pagamento riuscito e conferma dal webhook', async ({ page }) => {
        await bookAndOpenCheckout(page, inDays(20), inDays(22), 'chiara.4242@example.test');
        await snap(page, 'stripe-checkout');
        await fillCard(page, '4242 4242 4242 4242');
        await expectConfirmedOnReturn(page);
        await snap(page, 'stripe-confermata');
    });

    test('carta 3155: autenticazione 3D Secure completata', async ({ page }) => {
        await bookAndOpenCheckout(page, inDays(24), inDays(26), 'chiara.3155@example.test');
        await fillCard(page, '4000 0025 0000 3155');

        const challenge = threeDsChallenge(page);
        await challenge.getByRole('button', { name: /complete|completa/i }).click({ timeout: 30_000 });

        await expectConfirmedOnReturn(page);
    });

    test('carta 9995: rifiutata, la prenotazione resta in attesa di pagamento', async ({ page }) => {
        await bookAndOpenCheckout(page, inDays(28), inDays(30), 'chiara.9995@example.test');
        await fillCard(page, '4000 0000 0000 9995');

        await expect(page.getByText(/insufficient funds|fondi insufficienti|declined|rifiutata/i).first()).toBeVisible({ timeout: 30_000 });
        await snap(page, 'stripe-rifiutata');
        // Nessuna conferma: si resta su Stripe, la sessione rimane aperta per un altro tentativo.
        await expect(page).toHaveURL(/^https:\/\/checkout\.stripe\.com\//);
    });
});
