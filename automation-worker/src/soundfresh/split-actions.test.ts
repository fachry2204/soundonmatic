import { describe, expect, it } from 'vitest';
import { chromium } from 'playwright';
import { rejectSoundfreshRelease } from './reject-release.js';
import { readFileSync } from 'node:fs';

describe('Soundfresh split action safety', () => {
    it('opens only the arrow when Reject Release is in the Review Release dropdown', async () => {
        const browser = await chromium.launch({ headless: true, executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE });
        try {
            const page = await browser.newPage();
            await page.setContent(`<div class="btn-group"><button onclick="document.body.dataset.reviewed='yes'">Review Release</button>
                <button class="dropdown-toggle-split" data-bs-toggle="dropdown" onclick="document.querySelector('#menu').hidden=false">▼</button>
                <div id="menu" hidden><a onclick="document.querySelector('dialog').showModal()">Reject Release</a></div></div>
                <dialog><textarea></textarea><button onclick="document.body.dataset.rejected='yes';this.closest('dialog').close()">Reject Release</button></dialog>`);
            await rejectSoundfreshRelease(page, 'Reason from SoundOn');
            expect(await page.locator('textarea').inputValue()).toBe('Reason from SoundOn');
            expect(await page.locator('body').getAttribute('data-reviewed')).toBeNull();
            expect(await page.locator('body').getAttribute('data-rejected')).toBe('yes');
        } finally { await browser.close(); }
    }, 10000);
    it('ensures English for collection, extraction, rejection and Upload DSP routes', () => {
        const source = readFileSync(new URL('../server.ts', import.meta.url), 'utf8');
        for (const route of ['/v1/soundfresh/pending', '/v1/soundfresh/releases/extract', '/v1/soundfresh/releases/reject', '/v1/soundfresh/releases/upload-release']) {
            const handler = source.split(`app.post("${route}"`)[1]?.split('app.post(')[0] ?? '';
            expect(handler, route).toContain('await ensureSoundfreshEnglish(page)');
        }
    });
});
