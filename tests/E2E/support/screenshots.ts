import type { Page } from '@playwright/test';

/** Screenshot dei passi E2E: non committati (.gitignore). */
export const SCREENSHOT_DIR = 'storage/e2e-screenshots';

let step = 0;

export async function snap(page: Page, name: string): Promise<void> {
    step += 1;
    await page.screenshot({ path: `${SCREENSHOT_DIR}/${String(step).padStart(2, '0')}-${name}.png`, fullPage: true });
}
