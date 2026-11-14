import { describe, expect, it } from 'vitest';
import { chromium } from 'playwright';
import { extractReleaseLyrics, extractTrackLyrics } from './extract.js';
import { setTextField } from '../soundon/draft-driver.js';

describe('lyrics transfer', () => {
    it('copies inline lyrics beneath Show/Hide Lyrics without mixing tracks', async () => {
        const browser = await chromium.launch({ headless: true, executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE });
        try {
            const page = await browser.newPage();
            await page.setContent(`<div class="accordion-item"><table><tr><td>Title</td><td>Aku ikhlaskan</td></tr></table>
              <div><button onclick="document.getElementById('lyrics-a').hidden=false">Show/Hide Lyrics</button></div>
              <div id="lyrics-a" hidden><pre>[Verse 1]\nDi hati ini\n\n[Verse 2]\nKata mu janji</pre></div></div>`);
            expect(await extractReleaseLyrics(page)).toEqual([{ title: 'Aku ikhlaskan', lyrics: '[Verse 1]\nDi hati ini\n\n[Verse 2]\nKata mu janji' }]);
        } finally { await browser.close(); }
    }, 10000);
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

    it('reads a track-linked lyrics modal outside the accordion and closes it', async () => {
        const browser = await chromium.launch({ headless: true, executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE });
        try {
            const page = await browser.newPage();
            await page.setContent(`<div class="accordion-item"><button data-bs-target="#lyrics-modal" onclick="document.getElementById('lyrics-modal').hidden=false">Show/Hide Lyrics</button></div>
                <div id="lyrics-modal" role="dialog" hidden><h2>Lyrics</h2><textarea>Line one&#10;Line two</textarea><button onclick="this.parentElement.hidden=true">Close</button></div>`);
            expect(await extractTrackLyrics(page.locator('.accordion-item'))).toBe('Line one\nLine two');
            expect(await page.getByRole('dialog').count()).toBe(0);
        } finally { await browser.close(); }
    }, 10000);
});
