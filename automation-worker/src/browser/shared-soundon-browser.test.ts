import { describe, expect, it } from 'vitest';
import { acquireSoundOnPage, closeIdleSoundOnContext } from './shared-soundon.js';
import { setSearchField } from '../soundon/draft-driver.js';

describe('shared SoundOn context with Chromium', () => {
    it('commits separate artists concurrently in three scrolled editors on one profile', async () => {
        const state = { cookies: [{ name: 'session', value: 'fixture', domain: '.soundon.global', path: '/', expires: -1, httpOnly: true, secure: true, sameSite: 'Lax' as const }], origins: [] };
        const leases = await Promise.all([acquireSoundOnPage(state), acquireSoundOnPage(state), acquireSoundOnPage(state)]);
        try {
            expect(new Set(leases.map(lease => lease.context)).size).toBe(1);
            await Promise.all(leases.map(async (lease, index) => {
                const name = `Artist ${index + 1}`;
                await lease.page.setViewportSize({ width: 900, height: 480 });
                await lease.page.setContent(`<section><span class="soundon-formik-field-label">Primary artists</span><span id="saved"></span>
                    <button onclick="document.querySelector('#editor').hidden=false">Add</button></section>
                    <div id="editor" role="dialog" hidden style="position:fixed;top:20px;left:20px;width:600px;height:350px;overflow:auto;background:white">
                    <h4>Add primary artist</h4><div style="height:1200px"></div>
                    <div class="semi-select-content-wrapper" onmousedown="window.opened=event.isTrusted">Choose artist</div>
                    <input type="text"><div class="so-artist-preview-item-artist-name" onmousedown="window.selected=window.opened && event.isTrusted">${name}</div>
                    <button onclick="if(window.selected){document.querySelector('#saved').textContent='${name}';document.querySelector('#editor').hidden=true}">Submit</button></div>`);
                await setSearchField(lease.page, 'Primary artists', name);
                expect(await lease.page.locator('#saved').innerText()).toBe(name);
            }));
        } finally { await Promise.all(leases.map(lease => lease.release())); await closeIdleSoundOnContext(); }
    }, 30000);
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
        } finally { await Promise.all([one.release(), two.release(), three.release()]); await closeIdleSoundOnContext(); }
    }, 15000);
});
