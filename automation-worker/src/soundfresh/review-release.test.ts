import { describe, expect, it } from 'vitest';
import { chromium } from 'playwright';
import { moveSoundfreshReleaseToUnderReview } from './review-release.js';

describe('Soundfresh Review Release', () => {
    it('clicks the split-button primary Review Release once and accepts its native confirmation without opening the menu', async () => {
        const browser = await chromium.launch({ headless: true, executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE });
        try {
            const page = await browser.newPage();
            await page.setContent(`<button>EN</button><h1>View Release</h1><span class="badge">Pending</span>
                <div class="btn-group"><button aria-label="Review Release" class="dropdown-toggle-split" data-bs-toggle="dropdown" onclick="document.body.dataset.arrow='clicked'">▼</button>
                <button onclick="if(confirm('Review this release?')){document.body.dataset.primary='clicked';document.querySelector('.badge').textContent='Under Review';this.remove()}">Review Release</button></div>`);
            await moveSoundfreshReleaseToUnderReview(page, 'SoundOn', 'confirmed-draft');
            expect(await page.locator('body').getAttribute('data-arrow')).toBeNull();
            expect(await page.locator('body').getAttribute('data-primary')).toBe('clicked');
            expect(await page.locator('.badge').innerText()).toBe('Under Review');
        } finally { await browser.close(); }
    }, 10000);
    it('accepts the translated release page when the header EN indicator is missing after switching', async () => {
        const browser = await chromium.launch({ headless: true, executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE });
        try {
            const page = await browser.newPage();
            await page.setContent(`<button id="locale" onclick="document.querySelector('#english').hidden=false">ID</button>
                <button id="english" hidden onclick="document.querySelector('h1').textContent='View Release';document.querySelector('#review').textContent='Review Release';document.querySelector('#locale').remove();this.remove()">English</button>
                <h1>Lihat Rilisan</h1><span class="badge">Pending</span>
                <button id="review" onclick="document.querySelector('.badge').textContent='Under Review';this.remove()">Tinjau Rilisan</button>`);
            await moveSoundfreshReleaseToUnderReview(page, 'SoundOn', 'draft-5');
            expect(await page.locator('.badge').innerText()).toBe('Under Review');
        } finally { await browser.close(); }
    }, 10000);
    it('does not click Review again when the release status is already Under Review in English', async () => {
        const browser = await chromium.launch({ headless: true, executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE });
        try {
            const page = await browser.newPage();
            await page.setContent('<button onclick="document.body.dataset.languageClicked=\'yes\'">EN</button><span class="badge">Under Review</span>');
            await moveSoundfreshReleaseToUnderReview(page, 'SoundOn', 'draft-4');
            expect(await page.locator('body').getAttribute('data-languageClicked')).toBeNull();
        } finally { await browser.close(); }
    }, 10000);
    it('switches ID to English before invoking the direct Review Release action once', async () => {
        const browser = await chromium.launch({ headless: true, executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE });
        try {
            const page = await browser.newPage();
            await page.setContent(`<button id="locale" onclick="document.querySelector('#languages').hidden=false">ID</button>
                <div id="languages" hidden><button onclick="document.querySelector('#locale').textContent='EN';document.querySelector('#action').textContent='Review Release';this.parentElement.hidden=true">English</button><button>Bahasa Indonesia</button></div>
                <span class="badge">Pending</span><button id="action" onclick="document.body.dataset.clicks=String(Number(document.body.dataset.clicks||0)+1);document.querySelector('.badge').textContent='Under Review';this.remove()">Tinjau Rilisan</button>`);
            await moveSoundfreshReleaseToUnderReview(page, 'SoundOn', 'draft-1');
            expect(await page.locator('#locale').innerText()).toBe('EN');
            expect(await page.locator('.badge').innerText()).toBe('Under Review');
            expect(await page.locator('body').getAttribute('data-clicks')).toBe('1');
        } finally { await browser.close(); }
    }, 20000);

    it('fills the review modal, not Reject Release, and verifies the resulting badge', async () => {
        const browser = await chromium.launch({ headless: true, executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE });
        try {
            const page = await browser.newPage();
            await page.setContent(`<button>EN</button><span class="badge">Pending</span>
                <button onclick="document.body.dataset.rejected='yes'">Reject Release</button>
                <button onclick="document.querySelector('dialog').showModal()">Review Release</button>
                <dialog><label>Aggregator<select name="aggregator"><option>Other</option><option>SoundOn</option></select></label>
                <label>Draft reference<input name="draft"></label><button onclick="document.querySelector('.badge').textContent='Under Review';this.closest('dialog').close()">Confirm</button></dialog>`);
            await moveSoundfreshReleaseToUnderReview(page, 'SoundOn', 'draft-2');
            expect(await page.locator('select').inputValue()).toBe('SoundOn');
            expect(await page.locator('input').inputValue()).toBe('draft-2');
            expect(await page.locator('body').getAttribute('data-rejected')).toBeNull();
            expect(await page.locator('.badge').innerText()).toBe('Under Review');
        } finally { await browser.close(); }
    }, 30000);

    it('does not mistake the Under Review navigation tab for an already reviewed release', async () => {
        const browser = await chromium.launch({ headless: true, executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE });
        try {
            const page = await browser.newPage();
            await page.setContent('<button>EN</button><a>Under Review</a><span class="badge">Pending</span>');
            await expect(moveSoundfreshReleaseToUnderReview(page, 'SoundOn', 'draft-3')).rejects.toThrow('review_release_button');
        } finally { await browser.close(); }
    }, 10000);
});
