import { describe, expect, it, vi } from 'vitest';
import { chromium } from 'playwright';
import { clickEntityControl } from './draft-driver.js';

describe('entity control pointer interaction', () => {
    it('scrolls a nested editor and retries a viewport failure without a synthetic click', async () => {
        const browser = await chromium.launch({ headless: true, executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE });
        try {
            const page = await browser.newPage({ viewport: { width: 900, height: 480 } });
            await page.setContent(`<div role="dialog" style="height:200px;overflow:auto"><div style="height:1800px"></div>
                <button onmousedown="window.committed=event.isTrusted">Choose artist</button></div>`);
            const control = page.getByRole('button');
            const click = vi.spyOn(control, 'click').mockRejectedValueOnce(new Error('Element is outside of the viewport'));
            await clickEntityControl(control);
            expect(click).toHaveBeenCalledTimes(2);
            expect(await page.evaluate(() => (window as any).committed)).toBe(true);
            expect(await page.locator('[role=dialog]').evaluate(el => el.scrollTop)).toBeGreaterThan(0);
        } finally { await browser.close(); }
    }, 15000);

    it('does not force or synthesize a click on a disabled control', async () => {
        const browser = await chromium.launch({ headless: true, executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE });
        try {
            const page = await browser.newPage();
            await page.setContent('<button disabled onclick="window.committed=true">Choose artist</button>');
            await expect(clickEntityControl(page.getByRole('button'))).rejects.toThrow('entity_control_not_clickable');
            expect(await page.evaluate(() => (window as any).committed)).toBeUndefined();
        } finally { await browser.close(); }
    }, 20000);
});
