import { describe, expect, it } from 'vitest';
import { chromium } from 'playwright';
import { setSearchField, soundOnRolePattern } from './draft-driver.js';

describe('writer submission and role labels', () => {
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
