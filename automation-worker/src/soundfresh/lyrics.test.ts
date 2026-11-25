import { describe, expect, it } from 'vitest';
import { chromium } from 'playwright';
import { extractReleaseLyrics, extractTrackLyrics } from './extract.js';
import { setTextField } from '../soundon/draft-driver.js';

describe('lyrics transfer', () => {
    it('reopens each track in an exclusive accordion and transfers its complete lyrics', async () => {
        const browser = await chromium.launch({ headless: true, executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE });
        try {
            const page = await browser.newPage();
            const lyrics = '[Verse 1]\n' + Array.from({ length: 100 }, (_, i) => `Line ${i + 1}`).join('\n') + '\n\n[Outro]\nLast line';
            await page.setContent(`<script>
                function openTrack(button, id) {
                    document.querySelectorAll('.accordion-body').forEach(body => body.hidden = true);
                    document.querySelectorAll('.accordion-header button').forEach(header => header.setAttribute('aria-expanded', 'false'));
                    document.getElementById(id).hidden = false;
                    button.setAttribute('aria-expanded', 'true');
                }
                </script>
                ${[1, 2].map(id => `<div class="accordion-item">
                    <h2 class="accordion-header"><button aria-expanded="false" onclick="openTrack(this, 'track-${id}')">Track ${id}</button></h2>
                    <div class="accordion-body" id="track-${id}" hidden>
                        <table><tr><td>Title</td><td>Song ${id}</td></tr></table>
                        <button onclick="document.getElementById('lyrics-${id}').hidden = !document.getElementById('lyrics-${id}').hidden">Show/Hide Lyrics</button>
                        <div id="lyrics-${id}" hidden style="max-height:80px;overflow:auto"><pre></pre></div>
                    </div></div>`).join('')}
                <div><label class="soundon-formik-field-label">Lyrics (Optional)</label><textarea></textarea></div>`);
            await page.locator('#lyrics-1 pre').evaluate((node, value) => { node.textContent = value; }, lyrics);
            await page.locator('#lyrics-2 pre').evaluate(node => { node.textContent = 'Other song\nLast verse'; });
            expect(await extractReleaseLyrics(page)).toEqual([
                { title: 'Song 1', lyrics }, { title: 'Song 2', lyrics: 'Other song\nLast verse' },
            ]);
            await setTextField(page, 'Lyrics', lyrics);
            expect(await page.locator('textarea').inputValue()).toBe(lyrics);
        } finally { await browser.close(); }
    }, 15000);

    it('keeps an already visible lyrics box open even without aria-expanded', async () => {
        const browser = await chromium.launch({ headless: true, executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE });
        try {
            const page = await browser.newPage();
            await page.setContent(`<div class="accordion-item"><button onclick="document.getElementById('lyrics').hidden=true">Show/Hide Lyrics</button>
                <div id="lyrics"><pre>First line\n\nLast line</pre></div></div>`);
            expect(await extractTrackLyrics(page.locator('.accordion-item'))).toBe('First line\n\nLast line');
            expect(await extractTrackLyrics(page.locator('.accordion-item'))).toBe('First line\n\nLast line');
        } finally { await browser.close(); }
    }, 10000);

    it('waits for the lyrics box to appear after clicking the toggle', async () => {
        const browser = await chromium.launch({ headless: true, executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE });
        try {
            const page = await browser.newPage();
            await page.setContent(`<div class="accordion-item"><button onclick="setTimeout(() => document.getElementById('lyrics').hidden=false, 900)">Show/Hide Lyrics</button>
                <div id="lyrics" hidden><pre>First line\nLast line</pre></div></div>`);
            expect(await extractTrackLyrics(page.locator('.accordion-item'))).toBe('First line\nLast line');
        } finally { await browser.close(); }
    }, 10000);

    it('does not collapse track panels that are already expanded', async () => {
        const browser = await chromium.launch({ headless: true, executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE });
        try {
            const page = await browser.newPage();
            await page.setContent(`<button aria-expanded="true" onclick="document.querySelector('#track-panel').hidden=true">Track Information</button>
                <div id="track-panel"><div class="accordion-item"><h2 class="accordion-header"><button aria-expanded="true" onclick="document.querySelector('#body').hidden=true">Track 1</button></h2>
                <div id="body"><table><tr><td>Title</td><td>Sore Beranjak Malam</td></tr></table><button aria-controls="lyrics-6064" onclick="document.querySelector('#lyrics-6064').hidden=false">Show/Hide Lyrics</button><div id="lyrics-6064" hidden>First line<br>Second line</div></div></div></div>`);
            expect(await extractReleaseLyrics(page)).toEqual([{ title: 'Sore Beranjak Malam', lyrics: 'First line\nSecond line' }]);
        } finally { await browser.close(); }
    }, 10000);
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
