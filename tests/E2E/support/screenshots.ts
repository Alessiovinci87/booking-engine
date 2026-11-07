import { appendFileSync } from 'node:fs';

import AxeBuilder from '@axe-core/playwright';
import { expect, type Page } from '@playwright/test';

/** Screenshot dei passi E2E e risultati di axe: non committati (.gitignore). */
export const SCREENSHOT_DIR = 'storage/e2e-screenshots';

/** Regole WCAG 2.2 AA controllate da axe (8.2, ADR-0017). */
const WCAG_TAGS = ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa'];

/**
 * Con AXE_ENFORCE=0 le violazioni si registrano soltanto (misura "prima" di M8); altrimenti una
 * violazione seria o critica fa fallire il test.
 */
const ENFORCE = process.env.AXE_ENFORCE !== '0';

let step = 0;

export async function snap(page: Page, name: string): Promise<void> {
    step += 1;
    const label = `${String(step).padStart(2, '0')}-${name}`;
    await page.screenshot({ path: `${SCREENSHOT_DIR}/${label}.png`, fullPage: true });
    await checkAccessibility(page, label);
}

/** Controllo axe della pagina com'è adesso; risultati in axe.jsonl, una riga per controllo. */
export async function checkAccessibility(page: Page, label: string): Promise<void> {
    // Le risposte JSON dell'API mostrate nel browser non sono pagine dell'interfaccia.
    if ((await page.evaluate(() => document.contentType)) !== 'text/html') {
        return;
    }

    const results = await new AxeBuilder({ page }).withTags(WCAG_TAGS).analyze();
    const violations = results.violations.map((v) => ({ id: v.id, impact: v.impact, nodes: v.nodes.length, help: v.help, targets: v.nodes.slice(0, 3).map((n) => n.target.join(' ')) }));
    appendFileSync(`${SCREENSHOT_DIR}/axe.jsonl`, `${JSON.stringify({ label, url: new URL(page.url()).pathname, violations })}\n`);

    if (ENFORCE) {
        const serious = violations.filter((v) => v.impact === 'serious' || v.impact === 'critical');
        expect(serious, `violazioni axe serie o critiche in ${label}`).toEqual([]);
    }
}
