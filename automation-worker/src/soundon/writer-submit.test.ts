import { describe, expect, it, vi } from 'vitest';
import { chromium } from 'playwright';
import { setSearchField, soundOnRolePattern, submitEntityAndVerify } from './draft-driver.js';

describe('writer submission and role labels', () => {
    it('accepts a saved credit after the delivered click times out without clicking a new editor', async () => {
        const browser = await chromium.launch({ headless: true, executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE });
        try {
            const page = await browser.newPage();
            await page.setContent('<section><span class="soundon-formik-field-label">Songwriters</span><span id="saved"></span></section><div role="dialog" id="original"><button>Submit</button></div>');
            const dialog = page.locator('#original');
            const button = dialog.getByRole('button');
            const click = vi.spyOn(button, 'click').mockImplementationOnce(async () => {
                await page.evaluate(() => {
                    document.querySelector('#saved')!.textContent = 'Natasha Khairani';
                    document.querySelector('#original')!.remove();
                    document.body.insertAdjacentHTML('beforeend', '<div role="dialog"><button onclick="window.wrong=true">Submit</button></div>');
                });
                throw new Error('locator.click: Timeout exceeded after editor detached');
            });
            expect(await submitEntityAndVerify(page, dialog, button, 'Songwriters', 'Natasha Khairani', 1500)).toBe(true);
            expect(click).toHaveBeenCalledTimes(1);
            expect(await page.evaluate(() => (window as any).wrong)).toBeUndefined();
        } finally { await browser.close(); }
    }, 10000);

    it('does not look for Submit again when a closed editor already has a saved card', async () => {
        const browser = await chromium.launch({ headless: true, executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE });
        try {
            const page = await browser.newPage();
            await page.setContent('<section><span class="soundon-formik-field-label">Songwriters</span><span>Natasha Khairani</span></section>');
            const dialog = page.locator('#gone');
            const button = dialog.getByRole('button');
            const click = vi.spyOn(button, 'click');
            expect(await submitEntityAndVerify(page, dialog, button, 'Songwriters', 'Natasha Khairani', 1500)).toBe(true);
            expect(click).not.toHaveBeenCalled();
        } finally { await browser.close(); }
    }, 10000);

    it('does not report success when the editor disappears without a saved credit', async () => {
        const browser = await chromium.launch({ headless: true, executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE });
        try {
            const page = await browser.newPage();
            await page.setContent('<section><span class="soundon-formik-field-label">Songwriters</span></section><div id="original" role="dialog"><button onclick="this.parentElement.remove()">Submit</button></div>');
            const dialog = page.locator('#original');
            const button = dialog.getByRole('button');
            const click = vi.spyOn(button, 'click');
            await expect(submitEntityAndVerify(page, dialog, button, 'Songwriters', 'Natasha Khairani', 500))
                .rejects.toThrow('editor_closed_without_saved_card');
            expect(click).toHaveBeenCalledTimes(1);
        } finally { await browser.close(); }
    }, 10000);
    it('commits a plain writer name on blur and submits with real pointer events', async () => {
        const browser = await chromium.launch({ headless: true, executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE });
        try {
            const page = await browser.newPage();
            await page.setContent(`<section><span class="soundon-formik-field-label">Songwriters</span><span id="saved"></span>
                <button onclick="document.querySelector('dialog').showModal()">Add</button></section>
                <dialog class="so-form-modal-dialog"><h4>Add songwriter</h4><label>Name</label>
                <input type="text" onblur="window.committed=this.value">
                <button onmousedown="window.pointer=event.isTrusted" onclick="if(window.pointer && window.committed==='Natasha Khairani'){document.querySelector('#saved').textContent=window.committed;this.closest('dialog').close()}">Submit</button></dialog>`);
            await setSearchField(page, 'Songwriters', 'Natasha Khairani');
            expect(await page.locator('#saved').innerText()).toBe('Natasha Khairani');
            expect(await page.locator('dialog').isVisible()).toBe(false);
        } finally { await browser.close(); }
    }, 20000);

    it('allows equivalent vocal-editor labels but never unrelated roles', () => {
        for (const label of ['Vocal Editor', 'Vocal Editing', 'Vocal Edited']) expect(soundOnRolePattern('Vocal Edited').test(label)).toBe(true);
        for (const label of ['Vocalist', 'Producer', 'Assistant Vocal Editor']) expect(soundOnRolePattern('Vocal Edited').test(label)).toBe(false);
        expect(soundOnRolePattern('Producer').test('Co Producer')).toBe(false);
    });
});
