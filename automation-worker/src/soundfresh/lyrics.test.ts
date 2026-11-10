import { describe, expect, it } from 'vitest';
import { chromium } from 'playwright';
import { extractTrackLyrics } from './extract.js';
import { setTextField } from '../soundon/draft-driver.js';

describe('lyrics transfer', () => {
    it('opens each track separately and preserves the lyrics and line breaks in SoundOn', async () => {
        const browser = await chromium.launch({ headless: true, executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE });
        try {
            const page = await browser.newPage();
            await page.setContent(`
                <div class="accordion-item"><button aria-controls="lyrics1" onclick="document.getElementById('lyrics1').hidden=false">Show/Hide Lyrics</button><div id="lyrics1" hidden>First line<br>Second line</div></div>
                <div class="accordion-item"><button aria-controls="lyrics2" onclick="document.getElementById('lyrics2').hidden=false">Show/Hide Lyrics</button><div id="lyrics2" hidden>Other song<br>Other verse</div></div>
                <div class="accordion-item"><p>Instrumental</p></div>
                <div><label class="soundon-formik-field-label">Lyrics (Optional)</label><textarea></textarea></div>`);
            const tracks = page.locator('.accordion-item');
            const first = await extractTrackLyrics(tracks.nth(0));
            expect(first).toBe('First line\nSecond line');
            expect(await extractTrackLyrics(tracks.nth(1))).toBe('Other song\nOther verse');
            expect(await extractTrackLyrics(tracks.nth(2))).toBeNull();
            await setTextField(page, 'Lyrics', first!);
            expect(await page.locator('textarea').inputValue()).toBe(first);
        } finally { await browser.close(); }
    }, 15000);

    it('reports an unidentifiable lyrics panel instead of copying another track', async () => {
        const browser = await chromium.launch({ headless: true, executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE });
        try {
            const page = await browser.newPage();
            await page.setContent('<div class="accordion-item"><button>Show/Hide Lyrics</button></div>');
            await expect(extractTrackLyrics(page.locator('.accordion-item'))).rejects.toThrow('SOUNDFRESH_UI_CHANGED:lyrics_content');
        } finally { await browser.close(); }
    }, 10000);
});
