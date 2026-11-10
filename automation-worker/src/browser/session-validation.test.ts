import { describe, expect, it } from 'vitest';
import { chromium } from 'playwright';
import { dismissSoundOnTwoStepPrompt, sessionStateOnPage } from './session-validation.js';

describe('SoundOn optional two-step setup', () => {
    it('clicks Not now only on the optional security setup dialog', async () => {
        const browser = await chromium.launch({ headless: true, executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE });
        try {
            const page = await browser.newPage();
            await page.setContent('<div role="dialog"><h2>Set up 2-step verification</h2><p>Protect your account</p><button onclick="this.parentElement.remove()">Not now</button></div>');
            expect(await dismissSoundOnTwoStepPrompt(page)).toBe(true);
            expect(await page.getByRole('dialog').count()).toBe(0);
        } finally { await browser.close(); }
    }, 10000);
    it('does not dismiss a real verification-code challenge or an unrelated dialog', async () => {
        const browser = await chromium.launch({ headless: true, executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE });
        try {
            const page = await browser.newPage();
            await page.setContent('<div role="dialog"><h2>2-step verification to secure your account</h2><p>Enter your verification code</p><button>Not now</button></div>');
            expect(await dismissSoundOnTwoStepPrompt(page)).toBe(false);
            expect(await sessionStateOnPage(page, 'soundon')).toBe('manual_auth');
            await page.setContent('<div role="dialog">Publish release?<button>Not now</button></div>');
            expect(await dismissSoundOnTwoStepPrompt(page)).toBe(false);
        } finally { await browser.close(); }
    }, 10000);
});
