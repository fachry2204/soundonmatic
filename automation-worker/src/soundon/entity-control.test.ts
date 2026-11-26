import { describe, expect, it, vi } from 'vitest';
import { chromium } from 'playwright';
import { clickEntityControl, setSearchField } from './draft-driver.js';

describe('entity control pointer interaction', () => {
    it('identifies an unknown blocking editor without discarding its unsaved data', async () => {
        const browser = await chromium.launch({ headless: true, executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE });
        try {
            const page = await browser.newPage();
            await page.setContent(`<button id="date" onclick="window.wrong=true">Release date</button>
                <div role="dialog" style="position:fixed;inset:0;background:white"><h2>Primary artists</h2>
                <input value="Unsaved artist"><button onclick="this.parentElement.remove()">Cancel</button></div>`);
            await expect(clickEntityControl(page.locator('#date'))).rejects.toThrow('blocking_dialog_title="Primary artists"');
            expect(await page.locator('[role=dialog] input').inputValue()).toBe('Unsaved artist');
            expect(await page.evaluate(() => (window as any).wrong)).toBeUndefined();
        } finally { await browser.close(); }
    }, 20000);
    it('selects the full artist name only inside the active editor, even when its text is truncated', async () => {
        const browser = await chromium.launch({ headless: true, executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE });
        try {
            const page = await browser.newPage();
            await page.setContent(`<div artistname="Micky Musik" onmousedown="window.wrongArtist=true">Micky Musik</div>
                <section><span class="soundon-formik-field-label">Primary artists</span><span id="saved"></span>
                <button onclick="document.getElementById('editor').hidden=false">Add</button></section>
                <div id="editor" role="dialog" hidden><input type="text">
                <div artistname="Other artist" onmousedown="window.wrongArtist=true">Micky Musik</div>
                <div artistname="Micky Musik" onmousedown="window.selected=event.isTrusted">Micky Mus...</div>
                <button onclick="if(window.selected){document.getElementById('saved').textContent='Micky Musik';document.getElementById('editor').hidden=true}">Submit</button></div>`);
            await setSearchField(page, 'Primary artists', 'Micky Musik');
            expect(await page.locator('#saved').innerText()).toBe('Micky Musik');
            expect(await page.evaluate(() => (window as any).wrongArtist)).toBeUndefined();
        } finally { await browser.close(); }
    }, 15000);

    it('keeps an anchored dropdown open when its option is already in the viewport', async () => {
        const browser = await chromium.launch({ headless: true, executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE });
        try {
            const page = await browser.newPage({ viewport: { width: 900, height: 720 } });
            await page.setContent(`<div style="height:580px"></div>
                <div role="listbox"><button onmousedown="window.selected=event.isTrusted">Micky Musik</button></div>
                <div style="height:1800px"></div>
                <script>window.addEventListener('scroll', () => document.querySelector('[role=listbox]').hidden=true)</script>`);
            await clickEntityControl(page.getByRole('button'));
            expect(await page.evaluate(() => (window as any).selected)).toBe(true);
        } finally { await browser.close(); }
    }, 10000);

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
