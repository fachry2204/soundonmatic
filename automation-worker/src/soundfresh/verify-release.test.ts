import { describe, expect, it } from 'vitest';
import { chromium } from 'playwright';
import { verifySoundfreshRelease } from './verify-release.js';

describe('verifySoundfreshRelease', () => {
    it('selects English then Verify Release for Upload DSP and fills UPC and every track ISRC', async () => {
        const browser = await chromium.launch({ headless: true, executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE });
        try {
            const page = await browser.newPage();
            await page.setContent(`<button id="locale" onclick="document.querySelector('#english').hidden=false">ID</button>
                <button id="english" hidden onclick="document.querySelector('#locale').textContent='EN';document.querySelector('#verify').textContent='Verify Release';this.hidden=true">English</button>
                <span class="badge">Upload DSP</span><input aria-label="Search releases" value="unchanged">
                <input type="hidden" name="upc" value="old"><input type="hidden" name="isrc[0]" value="old">
                <button onclick="document.body.dataset.wrongAction='yes'">Review Release</button>
                <button onclick="document.body.dataset.wrongAction='yes'">Reject Release</button>
                <a href="#" id="verify" onclick="document.querySelector('dialog').showModal();return false">Verifikasi Rilisan</a>
                <dialog><label>UPC<input name="upc"></label>
                <label>Track 1 ISRC<input name="isrc[0]"></label><label>Track 2 ISRC<input name="isrc[1]"></label>
                <button onclick="document.body.dataset.saved='yes';this.closest('dialog').close()">Verify Release</button></dialog>`);
            await verifySoundfreshRelease(page, { upc: '1234567890123', isrcs: ['IDABC2612345', 'IDABC2612346'] });
            expect(await page.locator('#locale').innerText()).toBe('EN');
            expect(await page.locator('dialog input[name="upc"]').inputValue()).toBe('1234567890123');
            expect(await page.locator('dialog input[name="isrc[0]"]').inputValue()).toBe('IDABC2612345');
            expect(await page.locator('dialog input[name="isrc[1]"]').inputValue()).toBe('IDABC2612346');
            expect(await page.getByLabel('Search releases').inputValue()).toBe('unchanged');
            expect(await page.locator('body').getAttribute('data-wrongAction')).toBeNull();
            expect(await page.locator('body').getAttribute('data-saved')).toBe('yes');
        } finally { await browser.close(); }
    }, 20000);

    it('does not report verification success based on a Published navigation tab', async () => {
        const browser = await chromium.launch({ headless: true, executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE });
        try {
            const page = await browser.newPage();
            await page.setContent('<button>EN</button><a>Published</a><span class="badge">Upload DSP</span>');
            await expect(verifySoundfreshRelease(page, { upc: '1234567890123', isrcs: ['IDABC2612345'] }))
                .rejects.toThrow('verify_release_button');
        } finally { await browser.close(); }
    }, 10000);
});
