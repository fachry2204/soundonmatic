import { describe, expect, it } from 'vitest';
import { acquireSoundOnPage } from './shared-soundon.js';

describe('shared SoundOn context with Chromium', () => {
    it('keeps other pages alive when one upload page is closed', async () => {
        const state = { cookies: [{ name: 'session', value: 'fixture', domain: '.soundon.global', path: '/', expires: -1, httpOnly: true, secure: true, sameSite: 'Lax' as const }], origins: [] };
        const [one, two, three] = await Promise.all([acquireSoundOnPage(state), acquireSoundOnPage(state), acquireSoundOnPage(state)]);
        try {
            expect(one.context).toBe(two.context);
            expect(two.context).toBe(three.context);
            await one.page.close();
            expect(two.page.isClosed()).toBe(false);
            expect(three.page.isClosed()).toBe(false);
            await two.page.setContent('<h1>second upload remains open</h1>');
            expect(await two.page.locator('h1').innerText()).toBe('second upload remains open');
        } finally { await Promise.all([one.release(), two.release(), three.release()]); }
    }, 15000);
});
