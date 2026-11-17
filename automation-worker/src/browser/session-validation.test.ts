import { describe, expect, it, vi } from 'vitest';
import { chromium, type Page } from 'playwright';
import { dismissSoundOnTwoStepPrompt, sessionStateOnPage } from './session-validation.js';
import { closeContext, handleOptionalSoundOnPrompt, sessionContext } from './context-manager.js';

describe('SoundOn optional two-step setup', () => {
    it('handles errors from detached async popup handlers without terminating the worker', async () => {
        const warn = vi.spyOn(console, 'warn').mockImplementation(() => undefined);
        try {
            const brokenPage = { isClosed: () => false, url: () => 'https://www.soundon.global/library/list', locator: () => { throw new Error('page closed'); } } as unknown as Page;
            await expect(handleOptionalSoundOnPrompt(brokenPage)).resolves.toBeUndefined();
            expect(warn).toHaveBeenCalledOnce();
            const closedPage = { isClosed: () => true } as unknown as Page;
            await expect(handleOptionalSoundOnPrompt(closedPage)).resolves.toBeUndefined();
        } finally { warn.mockRestore(); }
    });
    it('dismisses the optional prompt during normal SoundOn page interaction', async () => {
        const context = await sessionContext();
        try {
            const page = await context.newPage();
            await page.route('https://www.soundon.global/**', route => route.fulfill({ contentType: 'text/html', body: `<button onclick="this.textContent='Done'">Continue</button>
                <div role="dialog"><h2>Enable 2-step verification</h2><button onclick="this.parentElement.remove()">Skip</button></div>` }));
            await page.goto('https://www.soundon.global/library/list');
            await page.getByRole('button', { name: 'Continue' }).click({ timeout: 5000 });
            expect(await page.getByRole('button', { name: 'Done' }).count()).toBe(1);
            expect(await page.getByRole('dialog').count()).toBe(0);
        } finally { await closeContext(context); }
    }, 15000);
    it('clicks Not now only on the optional security setup dialog', async () => {
        const browser = await chromium.launch({ headless: true, executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE });
        try {
            const page = await browser.newPage();
            await page.setContent('<div role="dialog"><h2>Set up 2-step verification</h2><p>Protect your account</p><button onclick="this.parentElement.remove()">Not now</button></div>');
            expect(await dismissSoundOnTwoStepPrompt(page)).toBe(true);
            expect(await page.getByRole('dialog').count()).toBe(0);
        } finally { await browser.close(); }
    }, 10000);
    it('clicks Skip on an optional two-step setup prompt', async () => {
        const browser = await chromium.launch({ headless: true, executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE });
        try {
            const page = await browser.newPage();
            await page.setContent('<div role="dialog"><h2>Enable 2-step verification</h2><button onclick="this.parentElement.remove()">Skip</button></div>');
            expect(await dismissSoundOnTwoStepPrompt(page)).toBe(true);
            expect(await page.getByRole('dialog').count()).toBe(0);
            await page.setContent('<div role="dialog"><h2>2-step verification</h2><input autocomplete="one-time-code"><button>Skip</button></div>');
            expect(await dismissSoundOnTwoStepPrompt(page)).toBe(false);
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
