import { describe, expect, it } from 'vitest';
import { chromium } from 'playwright';
import { moveSoundfreshReleaseToUploading } from './upload-release.js';

describe('Soundfresh Upload Release', () => {
    it('uses the main split action and verifies Upload DSP, not a navigation label', async () => {
        const browser = await chromium.launch({ headless: true, executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE });
        try {
            const page = await browser.newPage();
            await page.setContent(`<a>Uploading</a><span class="badge">Under Review</span><div class="btn-group">
                <button class="dropdown-toggle-split" data-bs-toggle="dropdown" aria-label="Upload Release" onclick="document.body.dataset.wrong='yes'">▼</button>
                <button onclick="if(confirm('Upload release?')){document.querySelector('.badge').textContent='Upload DSP';this.remove()}">Upload Release</button></div>`);
            await moveSoundfreshReleaseToUploading(page);
            expect(await page.locator('.badge').innerText()).toBe('Upload DSP');
            expect(await page.locator('body').getAttribute('data-wrong')).toBeNull();
        } finally { await browser.close(); }
    }, 10000);
});
