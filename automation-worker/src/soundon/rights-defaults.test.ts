import { describe, expect, it } from 'vitest';
import { chromium } from 'playwright';
import { registryRightsHolderDefault, setRightsHolderDefaults } from './draft-driver.js';

const fields = (prefix: string) => `
    <section><label class="soundon-formik-field-label">Record label</label><input id="${prefix}-label" value="Another label"></section>
    <section><label class="soundon-formik-field-label">P-line (Optional)</label><span>℗ 2026</span><input id="${prefix}-p" placeholder="Enter the sound recording rights holder."></section>
    <section><label class="soundon-formik-field-label">C-line (Optional)</label><span>© 2026</span><input id="${prefix}-c" placeholder="Enter the copyright owner."></section>`;

describe('SoundOn rights holder defaults', () => {
    it('fills all three fields with Soundfresh.ID without adding a year to the value', async () => {
        const browser = await chromium.launch({ headless: true, executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE });
        try {
            const page = await browser.newPage();
            await page.setContent(fields('single'));
            await setRightsHolderDefaults(page, true);
            for (const name of ['label', 'p', 'c']) expect(await page.locator(`#single-${name}`).inputValue()).toBe('Soundfresh.ID');
            expect(await page.getByText('℗ 2026').count()).toBe(1);
            expect(await page.getByText('© 2026').count()).toBe(1);
        } finally { await browser.close(); }
    }, 15000);

    it('fills each album track when its own editor is opened', async () => {
        const browser = await chromium.launch({ headless: true, executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE });
        try {
            const page = await browser.newPage();
            await page.setContent(`<details open id="track1"><summary>First</summary>${fields('one')}</details><details id="track2"><summary>Second</summary>${fields('two')}</details>`);
            await setRightsHolderDefaults(page);
            await page.locator('#track1').evaluate(element => (element as HTMLDetailsElement).open = false);
            await page.locator('#track2').evaluate(element => (element as HTMLDetailsElement).open = true);
            await setRightsHolderDefaults(page);
            for (const track of ['one', 'two']) for (const name of ['label', 'p', 'c'])
                expect(await page.locator(`#${track}-${name}`).inputValue()).toBe('Soundfresh.ID');
        } finally { await browser.close(); }
    }, 20000);

    it('leaves unrelated registry fields unchanged', () => {
        for (const path of ['record_label', 'tracks.0.p_line', 'tracks.1.c_line'])
            expect(registryRightsHolderDefault(path)).toBe('Soundfresh.ID');
        expect(registryRightsHolderDefault('custom', 'P-line (Optional)')).toBe('Soundfresh.ID');
        expect(registryRightsHolderDefault('title')).toBeNull();
        expect(registryRightsHolderDefault('artist_name')).toBeNull();
    });

    it('reports a displayed P-line without an editable control rather than writing another field', async () => {
        const browser = await chromium.launch({ headless: true, executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE });
        try {
            const page = await browser.newPage();
            await page.setContent(`<section><label class="soundon-formik-field-label">Record label</label><input></section>
                <section><label class="soundon-formik-field-label">P-line (Optional)</label><span>℗ 2026</span></section>
                <section><label class="soundon-formik-field-label">C-line (Optional)</label><input id="copyright"></section>`);
            await expect(setRightsHolderDefaults(page, true)).rejects.toThrow('SOUNDON_UI_CHANGED:control:P-line');
            expect(await page.locator('#copyright').inputValue()).toBe('');
        } finally { await browser.close(); }
    }, 10000);
});
