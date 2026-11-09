import { describe, expect, it } from 'vitest';
import { chromium } from 'playwright';
import { findDraft } from './draft-finder.js';

describe('live draft result readiness', () => {
    it('finds an existing draft after a delayed search instead of returning absent at 350ms', async () => {
        const browser = await chromium.launch({ headless: true, executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE });
        try {
            const page = await browser.newPage();
            await page.goto('data:text/html,<!doctype html>');
            await page.setContent(`<button role="tab" aria-selected="true">Drafts</button>
                <input placeholder="Find release by album name or artist name" oninput="document.querySelector('#busy').hidden=false;setTimeout(()=>{document.querySelector('tbody').innerHTML='<tr><td>My Song</td><td>Artist A</td></tr>';document.querySelector('#busy').hidden=true},3000)">
                <span id="busy" role="progressbar" hidden>Loading</span><table><tbody></tbody></table>`);
            const match = await findDraft(page, 'My Song', 'Artist A');
            expect(match?.found).toBe(true);
        } finally { await browser.close(); }
    }, 15000);
    it('allows a confirmed search with a different artist, not a title-only match', async () => {
        const browser = await chromium.launch({ headless: true, executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE });
        try {
            const page = await browser.newPage();
            await page.goto('data:text/html,<!doctype html>');
            await page.setContent('<button role="tab" aria-selected="true">Drafts</button><input placeholder="Search"><table><tbody><tr><td>My Song</td><td>Artist B</td></tr></tbody></table>');
            expect(await findDraft(page, 'My Song', 'Artist A')).toBeNull();
        } finally { await browser.close(); }
    }, 10000);
    it('blocks creation if the search reports an error', async () => {
        const browser = await chromium.launch({ headless: true, executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE });
        try {
            const page = await browser.newPage();
            await page.goto('data:text/html,<!doctype html>');
            await page.setContent('<button role="tab" aria-selected="true">Drafts</button><input placeholder="Search"><div role="alert">Failed to load releases</div>');
            await expect(findDraft(page, 'My Song', 'Artist A')).rejects.toThrow('DRAFT_LOOKUP_UNCONFIRMED');
        } finally { await browser.close(); }
    }, 10000);
});
