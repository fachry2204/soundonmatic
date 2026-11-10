import { describe, expect, it } from 'vitest';
import { chromium } from 'playwright';
import { ensureSoundfreshEnglish } from './language.js';

describe('Soundfresh collection UI language', () => {
    it('accepts an English release list with hidden language abbreviation', async () => {
        const browser = await chromium.launch({ headless: true, executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE });
        try {
            const page = await browser.newPage();
            await page.setContent('<span hidden>EN</span><table><thead><tr><th>Release Status</th><th>Release Date</th><th>Uploaded By</th></tr></thead></table>');
            await ensureSoundfreshEnglish(page);
            expect(await page.getByRole('columnheader').count()).toBe(3);
        } finally { await browser.close(); }
    }, 5000);

    it('handles translate icon text in a language dropdown and confirms an English list', async () => {
        const browser = await chromium.launch({ headless: true, executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE });
        try {
            const page = await browser.newPage();
            await page.setContent(`<button id="language" onclick="document.querySelector('#english').hidden=false"><span>translate</span> ID</button>
                <button id="english" hidden onclick="document.querySelector('thead').innerHTML='<tr><th>Release Status</th><th>Uploaded At</th></tr>';document.querySelector('#language').remove();this.remove()">English</button>
                <table><thead><tr><th>Status Rilisan</th><th>Tanggal Unggah</th></tr></thead></table>`);
            await ensureSoundfreshEnglish(page);
            expect(await page.getByRole('columnheader', { name: 'Release Status' }).count()).toBe(1);
        } finally { await browser.close(); }
    }, 5000);

    it('uses the icon-only language menu without clicking an unrelated dropdown', async () => {
        const browser = await chromium.launch({ headless: true, executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE });
        try {
            const page = await browser.newPage();
            await page.setContent(`<button onclick="document.body.dataset.wrong='yes'">Notifications</button>
                <a href="#" style="display:inline-block;width:24px;height:24px" onclick="document.querySelector('#english').hidden=false;return false"><i class="ri-translate-2"></i></a>
                <button id="english" hidden onclick="document.querySelector('thead').innerHTML='<tr><th>Release Date</th><th>Uploaded By</th></tr>';this.hidden=true">English</button>
                <table><thead><tr><th>Tanggal Rilis</th></tr></thead></table>`);
            await ensureSoundfreshEnglish(page);
            expect(await page.locator('body').getAttribute('data-wrong')).toBeNull();
        } finally { await browser.close(); }
    }, 5000);
});
